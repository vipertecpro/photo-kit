package com.vipertecpro.plugins.photo_kit

// =============================================================================
// PhotoKit — Android native side
// =============================================================================
//
// Five bridge functions, no third-party code (mirrors the iOS implementation):
//   • Save              — copy an image/video into MediaStore (Pictures/ or
//                         Movies/, optionally in an album sub-folder) so it
//                         shows up in the Gallery / Photos app. Result via event.
//   • PermissionStatus  — Android 10+ needs no runtime permission to add the
//                         app's own media, so this reports "notRequired".
//   • RequestPermission — answers via event (immediately on Android 10+).
//   • Process           — BitmapFactory + Matrix: decode downsampled, scale to
//                         a bounding box, bake the EXIF orientation, re-encode
//                         as JPEG / PNG / WebP at a quality. Synchronous.
//   • ReadExif          — ExifInterface → one flat, cross-platform map.
// =============================================================================

import android.Manifest
import android.content.ContentValues
import android.content.Context
import android.content.pm.PackageManager
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Matrix
import android.media.ExifInterface
import android.os.Build
import android.os.Environment
import android.os.Handler
import android.os.Looper
import android.provider.MediaStore
import android.util.Log
import android.webkit.MimeTypeMap
import androidx.core.content.ContextCompat
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import com.nativephp.mobile.utils.NativeActionCoordinator
import org.json.JSONObject
import java.io.File
import java.io.FileInputStream
import java.io.FileOutputStream
import java.io.IOException
import java.util.UUID
import java.util.concurrent.Executors
import kotlin.math.max
import kotlin.math.min
import kotlin.math.roundToInt

object PhotoKitFunctions {

    private const val TAG = "PhotoKit"
    private const val EVENT_SAVED = "Vipertecpro\\PhotoKit\\Events\\PhotoSaved"
    private const val EVENT_FAILED = "Vipertecpro\\PhotoKit\\Events\\PhotoSaveFailed"
    private const val EVENT_PERMISSION = "Vipertecpro\\PhotoKit\\Events\\PhotoLibraryPermissionResult"

    /** File copies run off the main thread, one at a time, in call order. */
    private val executor = Executors.newSingleThreadExecutor()

    private sealed class SaveResult {
        data class Success(val uri: String) : SaveResult()
        data class Failure(val reason: String, val message: String?) : SaveResult()
    }

    // ------------------------------------------------------------------ Save

    class Save(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val path = parameters["path"] as? String ?: ""
            val type = if ((parameters["type"] as? String) == "video") "video" else "image"
            val album = (parameters["album"] as? String)?.trim()?.takeIf { it.isNotEmpty() }
            val id = parameters["id"] as? String
            val hintExtension = (parameters["extension"] as? String)?.lowercase()?.takeIf { it.matches(Regex("[a-z0-9]{1,5}")) }

            executor.execute {
                val result = try {
                    saveToMediaStore(activity, path, type, album, hintExtension)
                } catch (e: Exception) {
                    Log.e(TAG, "save failed: ${e.message}", e)
                    SaveResult.Failure("write_failed", e.message)
                }
                Handler(Looper.getMainLooper()).post {
                    when (result) {
                        is SaveResult.Success -> dispatch(activity, EVENT_SAVED, JSONObject().apply {
                            put("path", path); put("type", type); put("uri", result.uri)
                            album?.let { put("album", it) }; id?.let { put("id", it) }
                        })
                        is SaveResult.Failure -> dispatch(activity, EVENT_FAILED, JSONObject().apply {
                            put("path", path); put("type", type); put("reason", result.reason)
                            result.message?.let { put("message", it) }; id?.let { put("id", it) }
                        })
                    }
                }
            }
            return emptyMap()
        }

        private fun saveToMediaStore(context: Context, path: String, type: String, album: String?, hintExtension: String? = null): SaveResult {
            val file = File(path)
            if (!file.isFile) return SaveResult.Failure("file_not_found", "No file at $path")

            if (Build.VERSION.SDK_INT < Build.VERSION_CODES.Q && !hasLegacyWritePermission(context)) {
                return SaveResult.Failure("permission_denied", "Android 9 and older need WRITE_EXTERNAL_STORAGE to add media.")
            }

            // Picker copies often have no extension; PHP sniffs the bytes and
            // passes the real one so MediaStore gets a proper type and name.
            val ownExtension = file.extension.lowercase()
            val known = MimeTypeMap.getSingleton().getMimeTypeFromExtension(ownExtension)
            val extension = if (known != null) ownExtension else (hintExtension ?: ownExtension)
            val mime = MimeTypeMap.getSingleton().getMimeTypeFromExtension(extension)
                ?: if (type == "video") "video/*" else "image/*"
            val displayName = if (known != null || hintExtension == null) file.name else "${file.name}.$extension"
            val isVideo = type == "video"
            val collection = if (isVideo) MediaStore.Video.Media.EXTERNAL_CONTENT_URI else MediaStore.Images.Media.EXTERNAL_CONTENT_URI
            val baseFolder = if (isVideo) Environment.DIRECTORY_MOVIES else Environment.DIRECTORY_PICTURES
            val relativePath = if (album != null) "$baseFolder/$album" else baseFolder

            val values = ContentValues().apply {
                put(MediaStore.MediaColumns.DISPLAY_NAME, displayName)
                put(MediaStore.MediaColumns.MIME_TYPE, mime)
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                    put(MediaStore.MediaColumns.RELATIVE_PATH, relativePath)
                    put(MediaStore.MediaColumns.IS_PENDING, 1)
                }
            }

            val resolver = context.contentResolver
            val uri = resolver.insert(collection, values)
                ?: return SaveResult.Failure("write_failed", "MediaStore refused to create the entry (unsupported type?).")

            try {
                val stream = resolver.openOutputStream(uri) ?: throw IOException("Could not open the MediaStore entry for writing.")
                stream.use { out -> FileInputStream(file).use { input -> input.copyTo(out) } }
            } catch (e: Exception) {
                resolver.delete(uri, null, null)
                return SaveResult.Failure("write_failed", e.message)
            }

            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                values.clear()
                values.put(MediaStore.MediaColumns.IS_PENDING, 0)
                resolver.update(uri, values, null, null)
            }
            return SaveResult.Success(uri.toString())
        }
    }

    // ------------------------------------------------------------ Permission

    class PermissionStatus(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            return mapOf("status" to permissionStatus(activity), "level" to levelName(parameters["level"]))
        }
    }

    class RequestPermission(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            // Android 10+ never needs a prompt to add the app's own media; on
            // older Android we report the current state (the plugin's minimum
            // is Android 10, so a prompt flow is intentionally not shipped).
            val payload = JSONObject().apply {
                put("status", permissionStatus(activity)); put("level", levelName(parameters["level"]))
            }
            Handler(Looper.getMainLooper()).post { dispatch(activity, EVENT_PERMISSION, payload) }
            return emptyMap()
        }
    }

    private fun permissionStatus(context: Context): String = when {
        Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q -> "notRequired"
        hasLegacyWritePermission(context) -> "granted"
        else -> "denied"
    }

    private fun hasLegacyWritePermission(context: Context): Boolean =
        ContextCompat.checkSelfPermission(context, Manifest.permission.WRITE_EXTERNAL_STORAGE) == PackageManager.PERMISSION_GRANTED

    private fun levelName(raw: Any?): String = if ((raw as? String) == "readWrite") "readWrite" else "add"

    // --------------------------------------------------------------- Process

    data class ProcessRequest(
        val path: String,
        val maxWidth: Int,
        val maxHeight: Int,
        val quality: Int,
        val format: String,
        val output: String?,
        val keepMetadata: Boolean,
    ) {
        companion object {
            fun from(p: Map<String, Any>): ProcessRequest {
                val rawFormat = ((p["format"] as? String) ?: "jpeg").lowercase()
                val output = (p["output"] as? String)?.trim()?.takeIf { it.isNotEmpty() }
                return ProcessRequest(
                    path = p["path"] as? String ?: "",
                    maxWidth = max(0, (p["maxWidth"] as? Number)?.toInt() ?: 0),
                    maxHeight = max(0, (p["maxHeight"] as? Number)?.toInt() ?: 0),
                    quality = ((p["quality"] as? Number)?.toInt() ?: 85).coerceIn(1, 100),
                    format = if (rawFormat == "jpg") "jpeg" else rawFormat,
                    output = output,
                    keepMetadata = (p["keepMetadata"] as? Boolean) ?: false,
                )
            }
        }
    }

    class Process(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val request = ProcessRequest.from(parameters)
            return try {
                ImageProcessor.process(context, request)
            } catch (e: PhotoKitException) {
                BridgeResponse.error(e.code, e.message ?: "Photo Kit failed")
            } catch (e: OutOfMemoryError) {
                BridgeResponse.error("EXECUTION_FAILED", "Not enough memory to process this image — pass a smaller maxWidth / maxHeight.")
            } catch (e: Exception) {
                Log.e(TAG, "process failed: ${e.message}", e)
                BridgeResponse.error("EXECUTION_FAILED", e.message ?: "Photo Kit failed")
            }
        }
    }

    class PhotoKitException(message: String, val code: String = "EXECUTION_FAILED") : Exception(message)

    object ImageProcessor {
        fun process(context: Context, r: ProcessRequest): Map<String, Any> {
            val file = File(r.path)
            if (!file.isFile) throw PhotoKitException("No file at ${r.path}", "INVALID_PARAMETERS")

            val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
            BitmapFactory.decodeFile(r.path, bounds)
            if (bounds.outWidth <= 0 || bounds.outHeight <= 0) {
                throw PhotoKitException("Could not decode ${file.name} as an image.")
            }

            val orientation = readOrientation(r.path)
            val swapped = orientation in 5..8
            val displayWidth = if (swapped) bounds.outHeight else bounds.outWidth
            val displayHeight = if (swapped) bounds.outWidth else bounds.outHeight

            // Scale to FIT the bounding box; never upscale.
            var scale = 1.0
            if (r.maxWidth > 0) scale = min(scale, r.maxWidth.toDouble() / displayWidth)
            if (r.maxHeight > 0) scale = min(scale, r.maxHeight.toDouble() / displayHeight)
            val targetStoredWidth = max(1, (bounds.outWidth * scale).roundToInt())
            val targetStoredHeight = max(1, (bounds.outHeight * scale).roundToInt())

            // Decode downsampled (power of two, never below the target) so a
            // full-resolution camera photo is not held in memory.
            var sample = 1
            while (bounds.outWidth / (sample * 2) >= targetStoredWidth && bounds.outHeight / (sample * 2) >= targetStoredHeight) sample *= 2
            val raw = BitmapFactory.decodeFile(r.path, BitmapFactory.Options().apply {
                inSampleSize = sample
                inPreferredConfig = Bitmap.Config.ARGB_8888
            }) ?: throw PhotoKitException("Could not decode ${file.name} as an image.")

            // Exact scale + EXIF orientation in one pass.
            val matrix = Matrix()
            val sx = targetStoredWidth.toFloat() / raw.width
            val sy = targetStoredHeight.toFloat() / raw.height
            if (sx != 1f || sy != 1f) matrix.postScale(sx, sy)
            applyOrientation(matrix, orientation)
            val upright = if (matrix.isIdentity) raw else {
                Bitmap.createBitmap(raw, 0, 0, raw.width, raw.height, matrix, true).also { if (it !== raw) raw.recycle() }
            }

            val (compressFormat, extension) = when (r.format) {
                "jpeg" -> Bitmap.CompressFormat.JPEG to "jpg"
                "png" -> Bitmap.CompressFormat.PNG to "png"
                "webp" -> (if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) Bitmap.CompressFormat.WEBP_LOSSY else @Suppress("DEPRECATION") Bitmap.CompressFormat.WEBP) to "webp"
                "heic" -> { upright.recycle(); throw PhotoKitException("HEIC output is not supported on Android — use jpeg, png or webp.", "INVALID_PARAMETERS") }
                else -> { upright.recycle(); throw PhotoKitException("Unknown output format \"${r.format}\".", "INVALID_PARAMETERS") }
            }

            val output = r.output?.let { File(it) } ?: File(context.cacheDir, "photokit_${UUID.randomUUID()}.$extension")
            output.parentFile?.mkdirs()
            if (output.exists()) output.delete()

            try {
                FileOutputStream(output).use { stream ->
                    if (!upright.compress(compressFormat, r.quality, stream)) {
                        throw PhotoKitException("Could not encode ${output.name}.")
                    }
                }
                if (r.keepMetadata && r.format == "jpeg") copyExif(r.path, output.absolutePath, upright.width, upright.height)
            } catch (e: Exception) {
                output.delete()
                throw e
            }

            val result = mapOf<String, Any>(
                "path" to output.absolutePath,
                "width" to upright.width,
                "height" to upright.height,
                "bytes" to output.length(),
                "format" to r.format,
                "originalWidth" to bounds.outWidth,
                "originalHeight" to bounds.outHeight,
                "originalBytes" to file.length(),
                "orientation" to orientation,
            )
            upright.recycle()
            return result
        }

        private fun applyOrientation(m: Matrix, orientation: Int) {
            when (orientation) {
                ExifInterface.ORIENTATION_ROTATE_90 -> m.postRotate(90f)
                ExifInterface.ORIENTATION_ROTATE_180 -> m.postRotate(180f)
                ExifInterface.ORIENTATION_ROTATE_270 -> m.postRotate(270f)
                ExifInterface.ORIENTATION_FLIP_HORIZONTAL -> m.postScale(-1f, 1f)
                ExifInterface.ORIENTATION_FLIP_VERTICAL -> m.postScale(1f, -1f)
                ExifInterface.ORIENTATION_TRANSPOSE -> { m.postRotate(90f); m.postScale(-1f, 1f) }
                ExifInterface.ORIENTATION_TRANSVERSE -> { m.postRotate(270f); m.postScale(-1f, 1f) }
                else -> {}
            }
        }

        /** Tags worth carrying over to an upright copy (orientation and sizes are rewritten). */
        private val COPIED_TAGS = listOf(
            ExifInterface.TAG_MAKE, ExifInterface.TAG_MODEL, ExifInterface.TAG_SOFTWARE, ExifInterface.TAG_DATETIME,
            "DateTimeOriginal", "DateTimeDigitized", "SubSecTime", "SubSecTimeOriginal", "SubSecTimeDigitized",
            ExifInterface.TAG_EXPOSURE_TIME, "FNumber", "ISOSpeedRatings", "PhotographicSensitivity",
            ExifInterface.TAG_FOCAL_LENGTH, "LensModel", "LensMake", ExifInterface.TAG_FLASH, ExifInterface.TAG_WHITE_BALANCE,
            "ExposureProgram", "ExposureBiasValue", "MeteringMode", "SceneCaptureType",
            ExifInterface.TAG_GPS_LATITUDE, ExifInterface.TAG_GPS_LATITUDE_REF, ExifInterface.TAG_GPS_LONGITUDE,
            ExifInterface.TAG_GPS_LONGITUDE_REF, ExifInterface.TAG_GPS_ALTITUDE, ExifInterface.TAG_GPS_ALTITUDE_REF,
            ExifInterface.TAG_GPS_TIMESTAMP, ExifInterface.TAG_GPS_DATESTAMP, ExifInterface.TAG_GPS_PROCESSING_METHOD,
        )

        private fun copyExif(from: String, to: String, width: Int, height: Int) {
            try {
                val source = ExifInterface(from)
                val target = ExifInterface(to)
                for (tag in COPIED_TAGS) source.getAttribute(tag)?.let { target.setAttribute(tag, it) }
                target.setAttribute(ExifInterface.TAG_ORIENTATION, "1")
                target.setAttribute(ExifInterface.TAG_IMAGE_WIDTH, width.toString())
                target.setAttribute(ExifInterface.TAG_IMAGE_LENGTH, height.toString())
                target.setAttribute("PixelXDimension", width.toString())
                target.setAttribute("PixelYDimension", height.toString())
                target.saveAttributes()
            } catch (e: Exception) {
                Log.w(TAG, "could not copy EXIF: ${e.message}")
            }
        }

        fun readOrientation(path: String): Int = try {
            val value = ExifInterface(path).getAttributeInt(ExifInterface.TAG_ORIENTATION, ExifInterface.ORIENTATION_NORMAL)
            if (value in 1..8) value else 1
        } catch (e: Exception) {
            1
        }
    }

    // -------------------------------------------------------------- ReadExif

    class ReadExif(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val path = parameters["path"] as? String ?: ""
            val file = File(path)
            if (!file.isFile) return BridgeResponse.error("INVALID_PARAMETERS", "No file at $path")

            val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
            BitmapFactory.decodeFile(path, bounds)
            if (bounds.outWidth <= 0 || bounds.outHeight <= 0) {
                return BridgeResponse.error("EXECUTION_FAILED", "Could not read ${file.name} as an image.")
            }

            val exif = try { ExifInterface(path) } catch (e: Exception) { null }
            val orientation = exif?.getAttributeInt(ExifInterface.TAG_ORIENTATION, 1)?.takeIf { it in 1..8 } ?: 1
            val swapped = orientation in 5..8

            val result = mutableMapOf<String, Any>(
                "width" to bounds.outWidth,
                "height" to bounds.outHeight,
                "displayWidth" to if (swapped) bounds.outHeight else bounds.outWidth,
                "displayHeight" to if (swapped) bounds.outWidth else bounds.outHeight,
                "orientation" to orientation,
                "bytes" to file.length(),
            )
            bounds.outMimeType?.let { result["mime"] = it }

            if (exif != null) {
                exif.getAttribute(ExifInterface.TAG_MAKE)?.let { result["make"] = it }
                exif.getAttribute(ExifInterface.TAG_MODEL)?.let { result["model"] = it }
                exif.getAttribute(ExifInterface.TAG_SOFTWARE)?.let { result["software"] = it }
                (exif.getAttribute("DateTimeOriginal") ?: exif.getAttribute(ExifInterface.TAG_DATETIME))
                    ?.let { result["dateTaken"] = normaliseDate(it) }
                exif.getAttribute(ExifInterface.TAG_EXPOSURE_TIME)?.toDoubleOrNull()?.let { result["exposureTime"] = it }
                exif.getAttribute("FNumber")?.let { rational -> parseRational(rational)?.let { result["fNumber"] = it } }
                (exif.getAttribute("ISOSpeedRatings") ?: exif.getAttribute("PhotographicSensitivity"))
                    ?.split(",", " ")?.firstOrNull()?.trim()?.toIntOrNull()?.let { result["iso"] = it }
                exif.getAttribute(ExifInterface.TAG_FOCAL_LENGTH)?.let { rational -> parseRational(rational)?.let { result["focalLength"] = it } }
                exif.getAttribute("LensModel")?.let { result["lensModel"] = it }

                val latLng = FloatArray(2)
                @Suppress("DEPRECATION")
                // The system photo picker redacts location to 0,0 unless the app
                // holds ACCESS_MEDIA_LOCATION; report "no location" instead of a
                // point in the Gulf of Guinea.
                if (exif.getLatLong(latLng) && !(latLng[0] == 0f && latLng[1] == 0f)) {
                    result["latitude"] = latLng[0].toDouble()
                    result["longitude"] = latLng[1].toDouble()
                }
                val altitude = exif.getAltitude(Double.NaN)
                if (!altitude.isNaN()) result["altitude"] = altitude
            }

            return result
        }

        /** EXIF rationals come as "28/10"; plain decimals pass through. */
        private fun parseRational(value: String): Double? {
            val parts = value.trim().split("/")
            return when (parts.size) {
                2 -> { val d = parts[1].toDoubleOrNull() ?: return null; if (d == 0.0) null else (parts[0].toDoubleOrNull() ?: return null) / d }
                else -> value.trim().toDoubleOrNull()
            }
        }
    }

    /** EXIF dates are "YYYY:MM:DD HH:MM:SS" — swap the date colons for dashes so PHP parses them. */
    fun normaliseDate(raw: String): String {
        if (raw.length < 10) return raw
        val chars = raw.toCharArray()
        if (chars[4] == ':') chars[4] = '-'
        if (chars[7] == ':') chars[7] = '-'
        return String(chars)
    }

    private fun dispatch(activity: FragmentActivity, event: String, payload: JSONObject) {
        NativeActionCoordinator.dispatchEvent(activity, event, payload.toString())
    }
}
