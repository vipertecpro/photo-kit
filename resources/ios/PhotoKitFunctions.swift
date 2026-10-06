import Foundation
import UIKit
import Photos
import ImageIO
import UniformTypeIdentifiers

// =============================================================================
// PhotoKit — iOS native side
// =============================================================================
//
// Five bridge functions, no third-party code:
//   • Save              — write an image/video into the Photos library
//                         (PHPhotoLibrary), asking for permission first,
//                         optionally into a named album. Result via event.
//   • PermissionStatus  — the add-only / read-write authorization status.
//   • RequestPermission — show the system prompt; result via event.
//   • Process           — ImageIO: decode with the EXIF orientation baked in,
//                         scale down to a bounding box, re-encode as
//                         JPEG / PNG / HEIC at a quality. Synchronous.
//   • ReadExif          — ImageIO properties → one flat, cross-platform map.
// =============================================================================

// MARK: - Bridge functions

enum PhotoKitFunctions {
    class Save: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let request = SaveRequest(parameters)
            DispatchQueue.main.async { PhotoLibrarySaver.shared.save(request) }
            return [:]
        }
    }

    class PermissionStatus: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let level = PhotoPermission.level(from: parameters["level"])
            return ["status": PhotoPermission.status(for: level), "level": PhotoPermission.name(of: level)]
        }
    }

    class RequestPermission: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let level = PhotoPermission.level(from: parameters["level"])
            PhotoPermission.request(level) { status in
                PhotoKitEvents.send(PhotoKitEvents.permission, ["status": status, "level": PhotoPermission.name(of: level)])
            }
            return [:]
        }
    }

    class Process: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            switch ImageProcessor.process(ProcessRequest(parameters)) {
            case .success(let result): return result
            case .failure(let error): return BridgeResponse.error(code: error.code, message: error.message)
            }
        }
    }

    class ReadExif: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let path = parameters["path"] as? String ?? ""
            switch ExifReader.read(path: path) {
            case .success(let result): return result
            case .failure(let error): return BridgeResponse.error(code: error.code, message: error.message)
            }
        }
    }
}

// MARK: - Events

enum PhotoKitEvents {
    static let saved = "Vipertecpro\\PhotoKit\\Events\\PhotoSaved"
    static let failed = "Vipertecpro\\PhotoKit\\Events\\PhotoSaveFailed"
    static let permission = "Vipertecpro\\PhotoKit\\Events\\PhotoLibraryPermissionResult"

    /// Deliver on the main thread with NSNull / nil values dropped, so PHP's
    /// event constructor only receives the keys that have a value.
    static func send(_ event: String, _ payload: [String: Any?]) {
        var clean: [String: Any] = [:]
        for (key, value) in payload {
            if let value, !(value is NSNull) { clean[key] = value }
        }
        let deliver: () -> Void = { LaravelBridge.shared.send?(event, clean) }
        if Thread.isMainThread {
            deliver()
        } else {
            DispatchQueue.main.async(execute: deliver)
        }
    }
}

struct PhotoKitError: Error {
    let code: String
    let message: String

    init(_ message: String, code: String = "EXECUTION_FAILED") {
        self.code = code
        self.message = message
    }
}

// MARK: - Permission

enum PhotoPermission {
    static func level(from raw: Any?) -> PHAccessLevel {
        (raw as? String) == "readWrite" ? .readWrite : .addOnly
    }

    static func name(of level: PHAccessLevel) -> String {
        level == .readWrite ? "readWrite" : "add"
    }

    static func status(for level: PHAccessLevel) -> String {
        describe(PHPhotoLibrary.authorizationStatus(for: level))
    }

    /// Prompts only when the status is still undetermined; otherwise answers
    /// straight away. The completion always runs on the main thread.
    static func request(_ level: PHAccessLevel, completion: @escaping (String) -> Void) {
        let current = PHPhotoLibrary.authorizationStatus(for: level)
        guard current == .notDetermined else {
            DispatchQueue.main.async { completion(describe(current)) }
            return
        }
        PHPhotoLibrary.requestAuthorization(for: level) { status in
            DispatchQueue.main.async { completion(describe(status)) }
        }
    }

    private static func describe(_ status: PHAuthorizationStatus) -> String {
        switch status {
        case .authorized: return "granted"
        case .limited: return "limited"
        case .denied: return "denied"
        case .restricted: return "restricted"
        case .notDetermined: return "notDetermined"
        @unknown default: return "unknown"
        }
    }
}

// MARK: - Save

struct SaveRequest {
    let path: String
    let type: String
    let album: String?
    let id: String?
    let extensionHint: String?

    init(_ p: [String: Any]) {
        path = p["path"] as? String ?? ""
        type = (p["type"] as? String) == "video" ? "video" : "image"
        let hint = (p["extension"] as? String)?.lowercased() ?? ""
        extensionHint = hint.range(of: "^[a-z0-9]{1,5}$", options: .regularExpression) != nil ? hint : nil
        let rawAlbum = (p["album"] as? String)?.trimmingCharacters(in: .whitespacesAndNewlines)
        album = (rawAlbum?.isEmpty ?? true) ? nil : rawAlbum
        id = p["id"] as? String
    }
}

final class PhotoLibrarySaver {
    static let shared = PhotoLibrarySaver()

    func save(_ request: SaveRequest) {
        guard FileManager.default.fileExists(atPath: request.path) else {
            fail(request, reason: "file_not_found", message: "No file at \(request.path)")
            return
        }

        // Adding to an album means reading the album list, which add-only
        // access does not allow — ask for full access only in that case.
        let level: PHAccessLevel = request.album == nil ? .addOnly : .readWrite

        PhotoPermission.request(level) { [weak self] status in
            guard status == "granted" || status == "limited" else {
                self?.fail(request, reason: "permission_denied", message: "Photo library access is \(status).")
                return
            }
            self?.write(request, level: level)
        }
    }

    private func write(_ request: SaveRequest, level: PHAccessLevel) {
        let url = Self.typedURL(for: request)
        var placeholderId: String?
        var created = false

        PHPhotoLibrary.shared().performChanges({
            let creation: PHAssetChangeRequest? = request.type == "video"
                ? PHAssetChangeRequest.creationRequestForAssetFromVideo(atFileURL: url)
                : PHAssetChangeRequest.creationRequestForAssetFromImage(atFileURL: url)

            guard let creation, let placeholder = creation.placeholderForCreatedAsset else { return }
            created = true
            placeholderId = placeholder.localIdentifier

            if let album = request.album, level == .readWrite {
                let collectionRequest: PHAssetCollectionChangeRequest?
                if let existing = Self.album(named: album) {
                    collectionRequest = PHAssetCollectionChangeRequest(for: existing)
                } else {
                    collectionRequest = PHAssetCollectionChangeRequest.creationRequestForAssetCollection(withTitle: album)
                }
                collectionRequest?.addAssets([placeholder] as NSArray)
            }
        }, completionHandler: { [weak self] success, error in
            if success, created {
                PhotoKitEvents.send(PhotoKitEvents.saved, [
                    "path": request.path, "type": request.type,
                    "uri": placeholderId, "album": request.album, "id": request.id,
                ])
            } else if !created {
                self?.fail(request, reason: "unsupported", message: "Photos could not read this file as \(request.type == "video" ? "a video" : "an image").")
            } else {
                self?.fail(request, reason: "write_failed", message: error?.localizedDescription)
            }
        })
    }

    /// Photos decides the type from the file extension. Picker copies and
    /// downloads can lack one, so link such a file under the sniffed
    /// extension (sent by PHP) in the temp directory before importing it.
    private static func typedURL(for request: SaveRequest) -> URL {
        let url = URL(fileURLWithPath: request.path)
        guard let hint = request.extensionHint, UTType(filenameExtension: url.pathExtension) == nil else {
            return url
        }
        let typed = FileManager.default.temporaryDirectory
            .appendingPathComponent("photokit_\(UUID().uuidString)")
            .appendingPathExtension(hint)
        do {
            try FileManager.default.linkItem(at: url, to: typed)
        } catch {
            guard (try? FileManager.default.copyItem(at: url, to: typed)) != nil else { return url }
        }
        return typed
    }

    private static func album(named title: String) -> PHAssetCollection? {
        let options = PHFetchOptions()
        options.predicate = NSPredicate(format: "localizedTitle = %@", title)
        return PHAssetCollection.fetchAssetCollections(with: .album, subtype: .albumRegular, options: options).firstObject
    }

    private func fail(_ request: SaveRequest, reason: String, message: String?) {
        PhotoKitEvents.send(PhotoKitEvents.failed, [
            "path": request.path, "type": request.type,
            "reason": reason, "message": message, "id": request.id,
        ])
    }
}

// MARK: - Process (resize / compress / fix orientation)

struct ProcessRequest {
    let path: String
    let maxWidth: Int
    let maxHeight: Int
    let quality: Double        // 0…1
    let format: String         // jpeg | png | webp | heic
    let output: String?
    let keepMetadata: Bool

    init(_ p: [String: Any]) {
        path = p["path"] as? String ?? ""
        maxWidth = max(0, (p["maxWidth"] as? NSNumber)?.intValue ?? 0)
        maxHeight = max(0, (p["maxHeight"] as? NSNumber)?.intValue ?? 0)
        let q = (p["quality"] as? NSNumber)?.doubleValue ?? 85
        quality = min(1, max(0.01, q / 100))
        let f = ((p["format"] as? String) ?? "jpeg").lowercased()
        format = f == "jpg" ? "jpeg" : f
        let out = (p["output"] as? String)?.trimmingCharacters(in: .whitespaces)
        output = (out?.isEmpty ?? true) ? nil : out
        keepMetadata = (p["keepMetadata"] as? Bool) ?? ((p["keepMetadata"] as? NSNumber)?.boolValue ?? false)
    }
}

enum ImageProcessor {
    static func process(_ r: ProcessRequest) -> Result<[String: Any], PhotoKitError> {
        let sourceURL = URL(fileURLWithPath: r.path)
        guard FileManager.default.fileExists(atPath: r.path) else {
            return .failure(PhotoKitError("No file at \(r.path)", code: "INVALID_PARAMETERS"))
        }
        guard let source = CGImageSourceCreateWithURL(sourceURL as CFURL, nil), CGImageSourceGetCount(source) > 0,
              let properties = CGImageSourceCopyPropertiesAtIndex(source, 0, nil) as? [CFString: Any],
              let storedWidth = (properties[kCGImagePropertyPixelWidth] as? NSNumber)?.intValue,
              let storedHeight = (properties[kCGImagePropertyPixelHeight] as? NSNumber)?.intValue,
              storedWidth > 0, storedHeight > 0 else {
            return .failure(PhotoKitError("Could not decode \(sourceURL.lastPathComponent) as an image."))
        }

        let orientation = (properties[kCGImagePropertyOrientation] as? NSNumber)?.intValue ?? 1
        let swapped = orientation >= 5 && orientation <= 8
        let displayWidth = swapped ? storedHeight : storedWidth
        let displayHeight = swapped ? storedWidth : storedHeight

        // Scale to FIT the bounding box; never upscale.
        var scale = 1.0
        if r.maxWidth > 0 { scale = min(scale, Double(r.maxWidth) / Double(displayWidth)) }
        if r.maxHeight > 0 { scale = min(scale, Double(r.maxHeight) / Double(displayHeight)) }
        let targetLongest = max(1, Int((Double(max(displayWidth, displayHeight)) * scale).rounded()))

        // One ImageIO call does decode + downsample + EXIF transform, and
        // never holds the full-size bitmap when a smaller one is requested.
        let thumbnailOptions: [CFString: Any] = [
            kCGImageSourceCreateThumbnailFromImageAlways: true,
            kCGImageSourceCreateThumbnailWithTransform: true,
            kCGImageSourceThumbnailMaxPixelSize: targetLongest,
            kCGImageSourceShouldCacheImmediately: true,
        ]
        guard let image = CGImageSourceCreateThumbnailAtIndex(source, 0, thumbnailOptions as CFDictionary) else {
            return .failure(PhotoKitError("Could not render \(sourceURL.lastPathComponent)."))
        }

        guard let type = Self.outputType(for: r.format) else {
            return .failure(PhotoKitError(
                r.format == "webp" ? "WebP output is not supported on iOS — use jpeg, png or heic." : "Unknown output format \"\(r.format)\".",
                code: "INVALID_PARAMETERS"
            ))
        }
        let supported = (CGImageDestinationCopyTypeIdentifiers() as? [String]) ?? []
        guard supported.contains(type.identifier) else {
            return .failure(PhotoKitError("This device cannot encode \(r.format) images.", code: "INVALID_PARAMETERS"))
        }

        let outputURL: URL
        if let output = r.output {
            outputURL = URL(fileURLWithPath: output)
        } else {
            outputURL = FileManager.default.temporaryDirectory
                .appendingPathComponent("photokit_\(UUID().uuidString)")
                .appendingPathExtension(Self.fileExtension(for: r.format))
        }
        try? FileManager.default.createDirectory(at: outputURL.deletingLastPathComponent(), withIntermediateDirectories: true)
        try? FileManager.default.removeItem(at: outputURL)

        guard let destination = CGImageDestinationCreateWithURL(outputURL as CFURL, type.identifier as CFString, 1, nil) else {
            return .failure(PhotoKitError("Could not create \(outputURL.lastPathComponent)."))
        }

        var destinationProperties: [CFString: Any] = [:]
        if r.format != "png" {
            destinationProperties[kCGImageDestinationLossyCompressionQuality] = r.quality
        }
        if r.keepMetadata {
            destinationProperties.merge(Self.uprightMetadata(from: properties, width: image.width, height: image.height)) { _, new in new }
        } else {
            destinationProperties[kCGImagePropertyOrientation] = 1
        }

        CGImageDestinationAddImage(destination, image, destinationProperties as CFDictionary)
        guard CGImageDestinationFinalize(destination) else {
            try? FileManager.default.removeItem(at: outputURL)
            return .failure(PhotoKitError("Could not write \(outputURL.lastPathComponent)."))
        }

        return .success([
            "path": outputURL.path,
            "width": image.width,
            "height": image.height,
            "bytes": Self.fileSize(at: outputURL.path),
            "format": r.format,
            "originalWidth": storedWidth,
            "originalHeight": storedHeight,
            "originalBytes": Self.fileSize(at: r.path),
            "orientation": orientation,
        ])
    }

    /// The source metadata with everything that described the OLD pixel
    /// layout removed or reset: the pixels are upright now, so the
    /// orientation becomes 1 and the stored dimensions become the new ones.
    private static func uprightMetadata(from properties: [CFString: Any], width: Int, height: Int) -> [CFString: Any] {
        var meta = properties
        meta[kCGImagePropertyOrientation] = 1
        meta[kCGImagePropertyPixelWidth] = width
        meta[kCGImagePropertyPixelHeight] = height

        if var tiff = meta[kCGImagePropertyTIFFDictionary] as? [CFString: Any] {
            tiff[kCGImagePropertyTIFFOrientation] = 1
            meta[kCGImagePropertyTIFFDictionary] = tiff
        }
        if var exif = meta[kCGImagePropertyExifDictionary] as? [CFString: Any] {
            exif[kCGImagePropertyExifPixelXDimension] = width
            exif[kCGImagePropertyExifPixelYDimension] = height
            meta[kCGImagePropertyExifDictionary] = exif
        }
        // Container-specific blocks must not be copied between formats.
        meta.removeValue(forKey: kCGImagePropertyJFIFDictionary)
        meta.removeValue(forKey: kCGImagePropertyPNGDictionary)
        meta.removeValue(forKey: kCGImagePropertyHEICSDictionary)
        return meta
    }

    private static func outputType(for format: String) -> UTType? {
        switch format {
        case "jpeg": return .jpeg
        case "png": return .png
        case "heic": return .heic
        default: return nil
        }
    }

    private static func fileExtension(for format: String) -> String {
        format == "jpeg" ? "jpg" : format
    }

    static func fileSize(at path: String) -> Int {
        ((try? FileManager.default.attributesOfItem(atPath: path))?[.size] as? NSNumber)?.intValue ?? 0
    }
}

// MARK: - EXIF

enum ExifReader {
    static func read(path: String) -> Result<[String: Any], PhotoKitError> {
        guard FileManager.default.fileExists(atPath: path) else {
            return .failure(PhotoKitError("No file at \(path)", code: "INVALID_PARAMETERS"))
        }
        let url = URL(fileURLWithPath: path)
        guard let source = CGImageSourceCreateWithURL(url as CFURL, nil), CGImageSourceGetCount(source) > 0,
              let properties = CGImageSourceCopyPropertiesAtIndex(source, 0, nil) as? [CFString: Any] else {
            return .failure(PhotoKitError("Could not read \(url.lastPathComponent) as an image."))
        }

        let width = (properties[kCGImagePropertyPixelWidth] as? NSNumber)?.intValue ?? 0
        let height = (properties[kCGImagePropertyPixelHeight] as? NSNumber)?.intValue ?? 0
        let orientation = (properties[kCGImagePropertyOrientation] as? NSNumber)?.intValue ?? 1
        let swapped = orientation >= 5 && orientation <= 8

        var result: [String: Any] = [
            "width": width,
            "height": height,
            "displayWidth": swapped ? height : width,
            "displayHeight": swapped ? width : height,
            "orientation": orientation,
            "bytes": ImageProcessor.fileSize(at: path),
        ]
        if let typeId = CGImageSourceGetType(source) as String?, let mime = UTType(typeId)?.preferredMIMEType {
            result["mime"] = mime
        }

        let tiff = properties[kCGImagePropertyTIFFDictionary] as? [CFString: Any] ?? [:]
        let exif = properties[kCGImagePropertyExifDictionary] as? [CFString: Any] ?? [:]
        let gps = properties[kCGImagePropertyGPSDictionary] as? [CFString: Any] ?? [:]

        put(&result, "make", tiff[kCGImagePropertyTIFFMake] as? String)
        put(&result, "model", tiff[kCGImagePropertyTIFFModel] as? String)
        put(&result, "software", tiff[kCGImagePropertyTIFFSoftware] as? String)
        put(&result, "dateTaken", normaliseDate((exif[kCGImagePropertyExifDateTimeOriginal] as? String) ?? (tiff[kCGImagePropertyTIFFDateTime] as? String)))
        put(&result, "exposureTime", (exif[kCGImagePropertyExifExposureTime] as? NSNumber)?.doubleValue)
        put(&result, "fNumber", (exif[kCGImagePropertyExifFNumber] as? NSNumber)?.doubleValue)
        put(&result, "iso", (exif[kCGImagePropertyExifISOSpeedRatings] as? [NSNumber])?.first?.intValue)
        put(&result, "focalLength", (exif[kCGImagePropertyExifFocalLength] as? NSNumber)?.doubleValue)
        put(&result, "lensModel", exif[kCGImagePropertyExifLensModel] as? String)

        if let lat = (gps[kCGImagePropertyGPSLatitude] as? NSNumber)?.doubleValue,
           let lng = (gps[kCGImagePropertyGPSLongitude] as? NSNumber)?.doubleValue {
            let latRef = (gps[kCGImagePropertyGPSLatitudeRef] as? String)?.uppercased() ?? "N"
            let lngRef = (gps[kCGImagePropertyGPSLongitudeRef] as? String)?.uppercased() ?? "E"
            result["latitude"] = latRef == "S" ? -abs(lat) : abs(lat)
            result["longitude"] = lngRef == "W" ? -abs(lng) : abs(lng)
        }
        if let altitude = (gps[kCGImagePropertyGPSAltitude] as? NSNumber)?.doubleValue {
            let belowSeaLevel = ((gps[kCGImagePropertyGPSAltitudeRef] as? NSNumber)?.intValue ?? 0) == 1
            result["altitude"] = belowSeaLevel ? -abs(altitude) : altitude
        }

        return .success(result)
    }

    private static func put(_ dict: inout [String: Any], _ key: String, _ value: Any?) {
        if let value { dict[key] = value }
    }

    /// EXIF dates are "YYYY:MM:DD HH:MM:SS" — swap the date colons for dashes
    /// so PHP's strtotime / Carbon parse them as-is.
    static func normaliseDate(_ raw: String?) -> String? {
        guard let raw, raw.count >= 10 else { return raw }
        var chars = Array(raw)
        if chars[4] == ":" { chars[4] = "-" }
        if chars[7] == ":" { chars[7] = "-" }
        return String(chars)
    }
}
