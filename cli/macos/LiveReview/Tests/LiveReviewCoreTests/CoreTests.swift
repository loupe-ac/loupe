import CoreGraphics
import Foundation
import ImageIO
import XCTest
@testable import LiveReviewCore

final class DeviceMatcherTests: XCTestCase {
    private let json = Data("""
    {"devices": {
      "com.apple.CoreSimulator.SimRuntime.iOS-26-5": [
        {"name": "iPhone 17 Pro", "udid": "A", "state": "Booted"},
        {"name": "iPhone 17", "udid": "B", "state": "Shutdown"}],
      "com.apple.CoreSimulator.SimRuntime.iOS-27-0": [
        {"name": "iPhone 17 Pro", "udid": "C", "state": "Booted"}],
      "com.apple.CoreSimulator.SimRuntime.watchOS-12-0": [
        {"name": "Watch", "udid": "W", "state": "Booted"}]
    }}
    """.utf8)

    func testParsesBootedIOSDevicesOnly() {
        let devices = DeviceMatcher.parseBooted(json)
        XCTAssertEqual(devices.map(\.udid), ["A", "C"])
        XCTAssertEqual(devices.map(\.osVersion), ["26.5", "27.0"])
    }

    func testMatchesTitleByNameAndVersion() {
        let devices = DeviceMatcher.parseBooted(json)
        XCTAssertEqual(DeviceMatcher.match(title: "iPhone 17 Pro – iOS 26.5", in: devices)?.udid, "A")
        XCTAssertEqual(DeviceMatcher.match(title: "iPhone 17 Pro – iOS 27.0", in: devices)?.udid, "C")
    }

    func testNoMatchForUnknownTitle() {
        let devices = DeviceMatcher.parseBooted(json)
        XCTAssertNil(DeviceMatcher.match(title: "iPhone 17 Pro", in: devices))
        XCTAssertNil(DeviceMatcher.match(title: "iPhone 17 – iOS 26.5", in: devices))
    }

    func testAmbiguousTitleGivesNoMatch() {
        let twins = [BootedDevice(name: "iPhone", udid: "1", osVersion: "26.5"), BootedDevice(name: "iPhone", udid: "2", osVersion: "26.5")]
        XCTAssertNil(DeviceMatcher.match(title: "iPhone – iOS 26.5", in: twins))
    }

    func testRuntimeWithPatchVersion() {
        XCTAssertEqual(DeviceMatcher.osVersion(runtime: "com.apple.CoreSimulator.SimRuntime.iOS-26-5-1"), "26.5.1")
        XCTAssertNil(DeviceMatcher.osVersion(runtime: "com.apple.CoreSimulator.SimRuntime.tvOS-26-0"))
    }
}

final class ElementTests: XCTestCase {
    private func el(_ role: String, _ label: String, y: Double, x: Double = 0, nav: Bool = false, desc: String = "") -> Element {
        Element(role: role, roleDescription: desc, label: label, frame: Frame(x: x, y: y, width: 50, height: 20), inNavigationBar: nav)
    }

    func testRemovesIdenticalEntries() {
        let button = el("AXButton", "Back", y: 10)
        XCTAssertEqual(ElementList.removingDuplicates([button, button, el("AXButton", "Back", y: 40)]).count, 2)
    }

    func testTitleIsFirstHeadingFromTheTop() {
        let list = [el("AXHeading", "Lower", y: 200), el("AXStaticText", "Body", y: 10), el("AXHeading", "Find your treadmill", y: 60)]
        XCTAssertEqual(ElementList.screenTitle(list), "Find your treadmill")
    }

    func testHeadingByRoleDescription() {
        XCTAssertEqual(ElementList.screenTitle([el("AXStaticText", "Settings", y: 5, desc: "heading")]), "Settings")
    }

    func testFallsBackToNavigationBarText() {
        let list = [el("AXStaticText", "Body", y: 10), el("AXStaticText", "Profile", y: 50, nav: true)]
        XCTAssertEqual(ElementList.screenTitle(list), "Profile")
    }

    func testEmptyWhenNeitherExists() {
        XCTAssertEqual(ElementList.screenTitle([el("AXStaticText", "Body", y: 10), el("AXHeading", "  ", y: 0)]), "")
    }

    func testDisplayRole() {
        XCTAssertEqual(el("AXButton", "", y: 0).displayRole, "Button")
    }
}

final class GeometryTests: XCTestCase {
    private let mapper = ScreenMapper(screen: Frame(x: 100, y: 200, width: 300, height: 600), imageWidth: 1200, imageHeight: 2400)!

    func testMapsAFrameToPixels() {
        let box = mapper.pixels(Frame(x: 110, y: 210, width: 30, height: 20))
        XCTAssertEqual(box, Frame(x: 40, y: 40, width: 120, height: 80))
    }

    func testClampsToTheImage() {
        let box = mapper.pixels(Frame(x: 50, y: 150, width: 400, height: 700))
        XCTAssertEqual(box, Frame(x: 0, y: 0, width: 1200, height: 2400))
    }

    func testStrokePointsAreFractions() {
        let stroke = mapper.stroke(from: [CGPoint(x: 100, y: 200), CGPoint(x: 250, y: 500)])
        XCTAssertEqual(stroke?.points, [CGPoint(x: 0, y: 0), CGPoint(x: 0.5, y: 0.5)])
        XCTAssertNil(mapper.stroke(from: [CGPoint(x: 1, y: 1)]))
    }

    func testRelativeFrameKeepsPoints() {
        XCTAssertEqual(mapper.relative(Frame(x: 110, y: 210, width: 30, height: 20)), Frame(x: 10, y: 10, width: 30, height: 20))
    }

    func testRejectsEmptyScreen() {
        XCTAssertNil(ScreenMapper(screen: Frame(x: 0, y: 0, width: 0, height: 10), imageWidth: 10, imageHeight: 10))
    }
}

final class ForegroundAppTests: XCTestCase {
    func testPrefersTheLastLaunchedThirdPartyApp() {
        let output = """
        PID\tStatus\tLabel
        700\t0\tUIKitApplication:com.apple.mobilecal[f26a][rb-legacy]
        710\t0\tUIKitApplication:com.example.One[1111][rb-legacy]
        720\t0\tUIKitApplication:com.example.Two[2222][rb-legacy]
        730\t0\tUIKitApplication:com.apple.Spotlight[3333][rb-legacy]
        """
        XCTAssertEqual(ForegroundApp.pick(launchctl: output), "com.example.Two")
    }

    func testFallsBackToASystemApp() {
        let output = "700\t0\tUIKitApplication:com.apple.mobilecal[f26a][rb-legacy]\n710\t0\tUIKitApplication:com.apple.chrono.WidgetRenderer-Default[9][rb-legacy]"
        XCTAssertEqual(ForegroundApp.pick(launchctl: output), "com.apple.mobilecal")
    }

    func testNoAppRunning() {
        XCTAssertNil(ForegroundApp.pick(launchctl: "1\t0\tcom.apple.something"))
    }

    func testDisplayName() {
        let plist = Data("{ \"com.example.One\" = { CFBundleDisplayName = Treadmatic; CFBundleName = TM; }; }".utf8)
        XCTAssertEqual(ForegroundApp.displayName(listapps: plist, bundleId: "com.example.One"), "Treadmatic")
        XCTAssertNil(ForegroundApp.displayName(listapps: plist, bundleId: "x"))
    }
}

final class NoteTests: XCTestCase {
    private func native(element: NoteElement? = nil) -> NativeFacts {
        NativeFacts(appName: "Treadmatic", bundleId: "com.example.tm", deviceName: "iPhone 17 Pro", udid: "U", osVersion: "26.5", screenTitle: "Find your treadmill", element: element)
    }

    private func object(_ note: Note) throws -> [String: Any] {
        try JSONSerialization.jsonObject(with: note.json()) as! [String: Any]
    }

    func testExistingCardNote() throws {
        let element = NoteElement(role: "Heading", label: "Find", identifier: nil, frame: Frame(x: 1, y: 2, width: 3, height: 4))
        let note = Note(body: "Cut off", destination: .card(id: "c1", label: "#1 x"), native: native(element: element), jpeg: Data([1, 2, 3]), deliveryId: "d1")
        let json = try object(note)
        XCTAssertEqual(json["surface"] as? String, "ios-simulator")
        XCTAssertEqual(json["deliveryId"] as? String, "d1")
        XCTAssertEqual((json["target"] as? [String: Any])?["cardId"] as? String, "c1")
        XCTAssertNil(json["url"])
        XCTAssertNil(json["strokes"])
        let facts = json["native"] as! [String: Any]
        XCTAssertEqual(facts["bundleId"] as? String, "com.example.tm")
        XCTAssertEqual(facts["screenTitle"] as? String, "Find your treadmill")
        let sent = facts["element"] as! [String: Any]
        XCTAssertEqual(sent["role"] as? String, "Heading")
        XCTAssertNil(sent["identifier"])
        XCTAssertEqual((sent["frame"] as? [String: Any])?["width"] as? Double, 3)
        let shot = json["screenshot"] as! [String: Any]
        XCTAssertEqual(shot["contentType"] as? String, "image/jpeg")
        XCTAssertEqual(shot["data"] as? String, "AQID")
    }

    func testNewCardNoteUnderAnEpic() throws {
        let note = Note(body: "b", destination: .eachNote(parentId: "e1"), native: native(), jpeg: Data(), deliveryId: "d")
        let target = try object(note)["target"] as! [String: Any]
        XCTAssertNil(target["cardId"])
        XCTAssertEqual((target["newCard"] as? [String: Any])?["parentCardId"] as? String, "e1")
    }

    func testNewCardNoteWithoutParentSendsAnEmptyObject() throws {
        for destination in [Destination.reviewCard, .eachNote(parentId: nil)] {
            let note = Note(body: "b", destination: destination, native: native(), jpeg: Data(), deliveryId: "d")
            let target = try object(note)["target"] as! [String: Any]
            XCTAssertEqual((target["newCard"] as? [String: Any])?.count, 0)
        }
    }

    func testSameDeliveryIdGivesSameBytes() throws {
        let note = Note(body: "b", destination: .reviewCard, native: native(), jpeg: Data([9]), deliveryId: "d")
        XCTAssertEqual(try note.json(), try note.json())
    }

    func testDeliveryIdIsStableForSameContentAndNewWhenItChanges() throws {
        let frame = Frame(x: 1, y: 2, width: 3, height: 4)
        func note(body: String = "b", frame: Frame = frame, jpeg: Data = Data([1])) -> Note {
            Note(body: body, destination: .reviewCard, native: native(element: NoteElement(role: "Button", label: "x", identifier: nil, frame: frame)), jpeg: jpeg)
        }
        var keeper = DeliveryKeeper()
        let first = try keeper.id(for: note())
        XCTAssertEqual(try keeper.id(for: note()), first)
        let body = try keeper.id(for: note(body: "other"))
        XCTAssertNotEqual(body, first)
        let moved = try keeper.id(for: note(body: "other", frame: Frame(x: 9, y: 2, width: 3, height: 4)))
        XCTAssertNotEqual(moved, body)
        let image = try keeper.id(for: note(body: "other", frame: Frame(x: 9, y: 2, width: 3, height: 4), jpeg: Data([2])))
        XCTAssertNotEqual(image, moved)
        keeper.reset()
        XCTAssertNotEqual(try keeper.id(for: note(body: "other", frame: Frame(x: 9, y: 2, width: 3, height: 4), jpeg: Data([2]))), image)
    }

    func testOnlyTheReviewCardBecomesTheSavedCard() {
        let result = SaveResult(commentId: "n", cardId: "c9", number: 9, label: "#9 Review", url: "")
        XCTAssertEqual(Destination.reviewCard.afterSave(result), .card(id: "c9", label: "#9 Review"))
        XCTAssertEqual(Destination.eachNote(parentId: nil).afterSave(result), .eachNote(parentId: nil))
        XCTAssertEqual(Destination.eachNote(parentId: "e").afterSave(result), .eachNote(parentId: "e"))
        XCTAssertEqual(Destination.card(id: "c1", label: "#1").afterSave(result), .card(id: "c1", label: "#1"))
    }

    func testSaveNoticeNamesTheCard() {
        let result = SaveResult(commentId: "n", cardId: "c12", number: 12, label: "#12 Review: Find your treadmill", url: "")
        XCTAssertEqual(result.notice, "Note saved to #12 Review: Find your treadmill")
    }

    func testANoteNeedsTextAndAMarkedImageToSave() {
        XCTAssertTrue(Note.canSave(body: "Too small", jpeg: Data([0xFF, 0xD8]), marked: true))
        XCTAssertFalse(Note.canSave(body: "Too small", jpeg: Data(), marked: true))
        XCTAssertFalse(Note.canSave(body: "  \n", jpeg: Data([0xFF, 0xD8]), marked: true))
    }

    func testAClickWithNoStrokeLeavesTheNoteUnsavable() {
        XCTAssertFalse(Note.canSave(body: "Too small", jpeg: Data([0xFF, 0xD8]), marked: false))
    }
}

final class WindowChooserTests: XCTestCase {
    private let ids = ["pro", "e"]
    private let windows = [Frame(x: 0, y: 0, width: 400, height: 800), Frame(x: 500, y: 0, width: 400, height: 800)]

    func testPicksTheWindowUnderThePointer() {
        XCTAssertEqual(WindowChooser.choose(ids: ids, windows: windows, pointer: CGPoint(x: 600, y: 100), current: "pro"), 1)
        XCTAssertEqual(WindowChooser.choose(ids: ids, windows: windows, pointer: CGPoint(x: 100, y: 100), current: "e"), 0)
    }

    func testKeepsTheCurrentWindowWhenThePointerIsElsewhere() {
        XCTAssertEqual(WindowChooser.choose(ids: ids, windows: windows, pointer: CGPoint(x: 2000, y: 100), current: "e"), 1)
        XCTAssertEqual(WindowChooser.choose(ids: ids, windows: windows, pointer: nil, current: "e"), 1)
    }

    func testFallsBackToTheFirstWindow() {
        XCTAssertEqual(WindowChooser.choose(ids: ids, windows: windows, pointer: nil, current: "gone"), 0)
        XCTAssertNil(WindowChooser.choose(ids: [], windows: [], pointer: CGPoint(x: 1, y: 1), current: nil))
    }
}

final class LaunchOptionsTests: XCTestCase {
    func testParsesAllOptions() {
        let options = LaunchOptions.parse(["--loupe", "/bin/loupe", "--dir", "/work", "--project", "p1"])
        XCTAssertEqual(options, LaunchOptions(loupePath: "/bin/loupe", folder: "/work", project: "p1"))
    }

    func testProjectIsOptionalAndLoupeIsRequired() {
        XCTAssertNil(LaunchOptions.parse(["--loupe", "/bin/loupe"])?.project)
        XCTAssertNil(LaunchOptions.parse(["--dir", "/work"]))
        XCTAssertNil(LaunchOptions.parse(["-NSDocumentRevisionsDebugMode", "YES"]))
    }

    func testProjectPreferencePerFolder() {
        let defaults = UserDefaults(suiteName: "live-review-test-\(UUID().uuidString)")!
        let preference = ProjectPreference(defaults: defaults)
        preference.remember("p1", for: "/a")
        XCTAssertEqual(preference.project(for: "/a"), "p1")
        XCTAssertNil(preference.project(for: "/b"))
    }
}

final class HelperClientTests: XCTestCase {
    private func client(_ answer: String, record: Recorder? = nil) -> HelperClient {
        HelperClient(run: { args, stdin in
            record?.calls.append((args, stdin))
            return Data(answer.utf8)
        })
    }

    final class Recorder: @unchecked Sendable {
        var calls: [([String], Data?)] = []
    }

    func testStatusStates() async throws {
        let state = try await client("{\"state\":\"signedOut\"}\n").status(project: nil)
        XCTAssertEqual(state, .signedOut)
    }

    func testStatusPassesTheProject() async throws {
        let record = Recorder()
        _ = try await client("{\"state\":\"ok\"}", record: record).status(project: "p1")
        XCTAssertEqual(record.calls.first?.0, ["status", "--project", "p1"])
    }

    func testErrorObjectThrows() async {
        do {
            _ = try await client("{\"error\":\"not_owner\",\"message\":\"no\"}").projects()
            XCTFail("expected an error")
        } catch let error as HelperError {
            XCTAssertEqual(error, HelperError(code: "not_owner", message: "no"))
        } catch {
            XCTFail("\(error)")
        }
    }

    func testGarbageOutputThrows() async {
        do {
            _ = try await client("nope").signIn()
            XCTFail("expected an error")
        } catch let error as HelperError {
            XCTAssertEqual(error.code, "invalid_output")
        } catch {
            XCTFail("\(error)")
        }
    }

    func testSignInAndProjectsAndTargets() async throws {
        let prompt = try await client("{\"code\":\"WDJB-MJHT\",\"url\":\"https://x\"}").signIn()
        XCTAssertEqual(prompt, SignInPrompt(code: "WDJB-MJHT", url: "https://x"))
        let projects = try await client("{\"projects\":[{\"id\":\"p\",\"name\":\"Loupe\"}]}").projects()
        XCTAssertEqual(projects, [Project(id: "p", name: "Loupe")])
        let record = Recorder()
        let cards = try await client("{\"cards\":[{\"cardId\":\"c\",\"number\":982,\"title\":\"Review\",\"status\":\"next\"}]}", record: record)
            .targets(project: "p", query: "rev", parentsOnly: true)
        XCTAssertEqual(cards.first?.label, "#982 Review")
        XCTAssertEqual(record.calls.first?.0, ["targets", "--project", "p", "--query", "rev", "--parent"])
    }

    func testNotesReadComments() async throws {
        let answer = "{\"notes\":{\"comments\":[{\"id\":\"n1\",\"body\":\"Back button has no label\",\"native\":{\"element\":{\"role\":\"Button\",\"identifier\":\"BackButton\"}}}]}}"
        let record = Recorder()
        let notes = try await client(answer, record: record).notes(project: "p", cardId: "c")
        XCTAssertEqual(notes, [OpenNote(id: "n1", body: "Back button has no label", detail: "Button · BackButton", cardLabel: nil)])
        XCTAssertEqual(record.calls.first?.0, ["notes", "--project", "p", "--scope", "card:c"])
    }

    func testSaveSendsTheNoteOnStdin() async throws {
        let record = Recorder()
        let facts = NativeFacts(appName: "A", bundleId: "b", deviceName: "d", udid: "u", osVersion: "1", screenTitle: "", element: nil)
        let note = Note(body: "hello", destination: .reviewCard, native: facts, jpeg: Data([1]), deliveryId: "d")
        let result = try await client("{\"commentId\":\"n\",\"cardId\":\"c\",\"number\":5,\"label\":\"#5 T\",\"url\":\"u\"}", record: record)
            .save(project: "p", note: note)
        XCTAssertEqual(result.number, 5)
        XCTAssertEqual(record.calls.first?.0, ["save", "--project", "p"])
        XCTAssertEqual(record.calls.first?.1, try note.json())
    }

    func testRealProcessRunsWithoutAShell() async throws {
        let dir = FileManager.default.temporaryDirectory.appendingPathComponent("live-review-\(UUID().uuidString)")
        try FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        defer { try? FileManager.default.removeItem(at: dir) }
        let script = dir.appendingPathComponent("loupe")
        try "#!/bin/sh\nread line\nprintf '{\"echo\":\"%s %s\"}\\n' \"$1\" \"$line\"\nexit 1\n".write(to: script, atomically: true, encoding: .utf8)
        try FileManager.default.setAttributes([.posixPermissions: 0o755], ofItemAtPath: script.path)
        let out = try await ProcessRunner.run(executable: script.path, arguments: ["review; touch pwned"], stdin: Data("hi\n".utf8))
        XCTAssertEqual(String(decoding: out, as: UTF8.self), "{\"echo\":\"review; touch pwned hi\"}\n")
        XCTAssertFalse(FileManager.default.fileExists(atPath: "pwned"))
    }

    private func loupeScript(_ body: String) throws -> (path: String, cleanup: () -> Void) {
        let dir = FileManager.default.temporaryDirectory.appendingPathComponent("live-review-\(UUID().uuidString)")
        try FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        let script = dir.appendingPathComponent("loupe")
        try "#!/bin/sh\n\(body)\n".write(to: script, atomically: true, encoding: .utf8)
        try FileManager.default.setAttributes([.posixPermissions: 0o755], ofItemAtPath: script.path)

        return (script.path, { try? FileManager.default.removeItem(at: dir) })
    }

    func testNonZeroExitWithErrorObjectYieldsThatError() async throws {
        let script = try loupeScript("echo '{\"error\":\"signed_out\",\"message\":\"sign in again\"}'\nexit 1")
        defer { script.cleanup() }
        do {
            _ = try await HelperClient(loupePath: script.path).projects()
            XCTFail("expected an error")
        } catch let error as HelperError {
            XCTAssertEqual(error, HelperError(code: "signed_out", message: "sign in again"))
        }
    }

    func testChildThatExitsBeforeReadingStdinDoesNotKillTheCaller() async throws {
        let script = try loupeScript("echo '{\"error\":\"early\",\"message\":\"bye\"}'\nexit 1")
        defer { script.cleanup() }
        let facts = NativeFacts(appName: "A", bundleId: "b", deviceName: "d", udid: "u", osVersion: "1", screenTitle: "", element: nil)
        let note = Note(body: "hello", destination: .reviewCard, native: facts, jpeg: Data(count: 1 << 20))
        for _ in 0..<5 {
            do {
                _ = try await HelperClient(loupePath: script.path).save(project: "p", note: note)
                XCTFail("expected an error")
            } catch let error as HelperError {
                XCTAssertEqual(error.code, "early")
            }
        }
    }

    func testMissingBinaryIsALaunchError() async {
        do {
            _ = try await ProcessRunner.run(executable: "/nonexistent/loupe", arguments: [])
            XCTFail("expected an error")
        } catch let error as HelperError {
            XCTAssertEqual(error.code, "launch_failed")
        } catch {
            XCTFail("\(error)")
        }
    }
}

final class ImageRendererTests: XCTestCase {
    private func png(width: Int, height: Int) -> Data {
        let context = CGContext(data: nil, width: width, height: height, bitsPerComponent: 8, bytesPerRow: 0,
                                space: CGColorSpace(name: CGColorSpace.sRGB)!, bitmapInfo: CGImageAlphaInfo.noneSkipLast.rawValue)!
        context.setFillColor(CGColor(red: 1, green: 1, blue: 1, alpha: 1))
        context.fill(CGRect(x: 0, y: 0, width: width, height: height))
        let out = NSMutableData()
        let destination = CGImageDestinationCreateWithData(out, "public.png" as CFString, 1, nil)!
        CGImageDestinationAddImage(destination, context.makeImage()!, nil)
        CGImageDestinationFinalize(destination)
        return out as Data
    }

    private func pixel(_ jpeg: Data, x: Int, y: Int) -> (r: UInt8, g: UInt8, b: UInt8) {
        let image = CGImageSourceCreateImageAtIndex(CGImageSourceCreateWithData(jpeg as CFData, nil)!, 0, nil)!
        let context = CGContext(data: nil, width: image.width, height: image.height, bitsPerComponent: 8, bytesPerRow: 0,
                                space: CGColorSpace(name: CGColorSpace.sRGB)!, bitmapInfo: CGImageAlphaInfo.noneSkipLast.rawValue)!
        context.draw(image, in: CGRect(x: 0, y: 0, width: image.width, height: image.height))
        let data = context.data!.assumingMemoryBound(to: UInt8.self)
        let row = y
        let offset = row * context.bytesPerRow + x * 4
        return (data[offset], data[offset + 1], data[offset + 2])
    }

    func testScalesTheLongEdgeTo1568() throws {
        let rendered = try XCTUnwrap(ImageRenderer.render(png: png(width: 1206, height: 2622), marks: Marks(box: Frame(x: 100, y: 100, width: 200, height: 100))))
        XCTAssertEqual(rendered.height, 1568)
        XCTAssertEqual(rendered.width, 721)
        XCTAssertTrue(rendered.jpeg.starts(with: [0xFF, 0xD8]))
    }

    func testDoesNotUpscaleASmallImage() throws {
        let rendered = try XCTUnwrap(ImageRenderer.render(png: png(width: 400, height: 800), marks: Marks()))
        XCTAssertEqual(rendered.width, 400)
        XCTAssertEqual(rendered.height, 800)
    }

    func testBoxMarksTheElementAndLeavesTheRestWhite() throws {
        let rendered = try XCTUnwrap(ImageRenderer.render(png: png(width: 400, height: 800), marks: Marks(box: Frame(x: 100, y: 100, width: 200, height: 100))))
        let edge = pixel(rendered.jpeg, x: 101, y: 150)
        XCTAssertGreaterThan(edge.r, 200)
        XCTAssertLessThan(edge.b, 120)
        let outside = pixel(rendered.jpeg, x: 20, y: 700)
        XCTAssertGreaterThan(outside.b, 230)
    }

    func testStrokeMarksItsPath() throws {
        let stroke = Stroke(points: [CGPoint(x: 0.1, y: 0.5), CGPoint(x: 0.9, y: 0.5)])
        let rendered = try XCTUnwrap(ImageRenderer.render(png: png(width: 400, height: 800), marks: Marks(strokes: [stroke])))
        XCTAssertLessThan(pixel(rendered.jpeg, x: 200, y: 400).b, 120)
        XCTAssertGreaterThan(pixel(rendered.jpeg, x: 200, y: 100).b, 230)
    }

    func testStrokeFlipAwayFromTheMiddle() throws {
        let stroke = Stroke(points: [CGPoint(x: 0.1, y: 0.25), CGPoint(x: 0.9, y: 0.25)])
        let rendered = try XCTUnwrap(ImageRenderer.render(png: png(width: 400, height: 800), marks: Marks(strokes: [stroke])))
        XCTAssertLessThan(pixel(rendered.jpeg, x: 200, y: 200).b, 120)
        XCTAssertGreaterThan(pixel(rendered.jpeg, x: 200, y: 600).b, 230)
    }

    func testBoxPositionAfterScalingDown() throws {
        let rendered = try XCTUnwrap(ImageRenderer.render(png: png(width: 1206, height: 2622), marks: Marks(box: Frame(x: 200, y: 400, width: 400, height: 300))))
        let scale = Double(rendered.height) / 2622
        let left = Int((200 * scale).rounded()) + 1
        let mid = Int((550 * scale).rounded())
        XCTAssertLessThan(pixel(rendered.jpeg, x: left, y: mid).b, 120)
        XCTAssertGreaterThan(pixel(rendered.jpeg, x: Int((100 * scale).rounded()), y: mid).b, 230)
        XCTAssertGreaterThan(pixel(rendered.jpeg, x: left, y: Int((900 * scale).rounded())).b, 230)
    }

    func testBoxAndStrokesShipTogether() throws {
        let stroke = Stroke(points: [CGPoint(x: 0.1, y: 0.75), CGPoint(x: 0.9, y: 0.75)])
        let marks = Marks(box: Frame(x: 100, y: 100, width: 200, height: 100), strokes: [stroke])
        let rendered = try XCTUnwrap(ImageRenderer.render(png: png(width: 400, height: 800), marks: marks))
        XCTAssertLessThan(pixel(rendered.jpeg, x: 101, y: 150).b, 120)
        XCTAssertLessThan(pixel(rendered.jpeg, x: 200, y: 600).b, 120)
    }

    func testRejectsNonImageData() {
        XCTAssertNil(ImageRenderer.render(png: Data([1, 2, 3]), marks: Marks()))
    }
}

final class CaptureSessionTests: XCTestCase {
    private let jpeg = RenderedImage(jpeg: Data([0xFF, 0xD8]), width: 1, height: 1)
    private let element = Element(role: "AXButton", roleDescription: "button", label: "Buy", frame: Frame(x: 0, y: 0, width: 50, height: 20), inNavigationBar: false)

    private func made(element: Element? = nil) -> Capture {
        Capture(device: BootedDevice(name: "iPhone", udid: "A", osVersion: "26.5"), app: nil, screenTitle: "Home", element: element,
                mapper: ScreenMapper(screen: Frame(x: 0, y: 0, width: 100, height: 200), imageWidth: 100, imageHeight: 200)!, png: Data())
    }

    private func marked(_ session: inout CaptureSession) {
        let token = session.beginFreeze()
        XCTAssertEqual(session.endFreeze(token, with: made(element: element), render: { _ in self.jpeg }), .ready)
    }

    func testASecondPickLeavesNothingToSaveWhileItsScreenshotIsTaken() {
        var session = CaptureSession()
        marked(&session)
        XCTAssertNotNil(session.ready)

        let second = session.beginFreeze()
        XCTAssertNil(session.capture)
        XCTAssertNil(session.ready)

        XCTAssertEqual(session.endFreeze(second, with: nil), .screenshotFailed)
        XCTAssertNil(session.ready)
    }

    func testAnOlderScreenshotDoesNotReplaceANewerPick() {
        var session = CaptureSession()
        let first = session.beginFreeze()
        let second = session.beginFreeze()
        XCTAssertEqual(session.endFreeze(first, with: made(element: element), render: { _ in self.jpeg }), .stale)
        XCTAssertTrue(session.freezing)
        XCTAssertEqual(session.endFreeze(second, with: made(element: element), render: { _ in self.jpeg }), .ready)
        XCTAssertNotNil(session.ready)
    }

    func testAClearMakesTheScreenshotInFlightStale() {
        var session = CaptureSession()
        let token = session.beginFreeze()
        session.clear()
        XCTAssertEqual(session.endFreeze(token, with: made(element: element), render: { _ in self.jpeg }), .stale)
        XCTAssertNil(session.capture)
    }

    func testAStrokeThatFailsToRenderKeepsSaveOffUntilARenderSucceeds() {
        var session = CaptureSession()
        marked(&session)

        XCTAssertFalse(session.add(stroke: [CGPoint(x: 1, y: 1), CGPoint(x: 5, y: 5)], render: { _ in nil }))
        XCTAssertEqual(session.capture?.strokes.count, 1)
        XCTAssertNil(session.ready)

        XCTAssertTrue(session.add(stroke: [CGPoint(x: 2, y: 2), CGPoint(x: 6, y: 6)], render: { $0.strokes.count == 2 ? self.jpeg : nil }))
        XCTAssertNotNil(session.ready)
    }

    func testAFailedRenderOfANewPickIsNotSavable() {
        var session = CaptureSession()
        let token = session.beginFreeze()
        XCTAssertEqual(session.endFreeze(token, with: made(element: element), render: { _ in nil }), .renderFailed)
        XCTAssertNil(session.ready)
    }

    func testStrokesDrawnDuringTheScreenshotJoinTheCapture() {
        var session = CaptureSession()
        let token = session.beginFreeze()
        XCTAssertTrue(session.add(stroke: [CGPoint(x: 1, y: 1), CGPoint(x: 5, y: 5)], render: { _ in nil }))
        XCTAssertEqual(session.endFreeze(token, with: made(), render: { _ in self.jpeg }), .ready)
        XCTAssertEqual(session.ready?.strokes.count, 1)
    }
}

final class LatestRequestTests: XCTestCase {
    func testOnlyTheNewestAnswerIsUsedWhenAnswersArriveInReverse() {
        var requests = LatestRequest()
        let cardA = requests.begin()
        let cardB = requests.begin()
        XCTAssertTrue(requests.isCurrent(cardB))
        XCTAssertFalse(requests.isCurrent(cardA))
    }

    func testACancelDropsTheAnswerInFlight() {
        var requests = LatestRequest()
        let token = requests.begin()
        requests.cancel()
        XCTAssertFalse(requests.isCurrent(token))
    }
}

final class ProjectSelectionTests: XCTestCase {
    func testLinkedProjectStaysWhenTheLoginIsNotItsOwner() {
        var selection = ProjectSelection(linked: "linked", remembered: "other")
        XCTAssertFalse(selection.notOwner())
        XCTAssertEqual(selection.id, "linked")
    }

    func testLinkedProjectRefusesAPickOfAnotherProject() {
        var selection = ProjectSelection(linked: "linked", remembered: nil)
        XCTAssertFalse(selection.choose("owned"))
        XCTAssertEqual(selection.id, "linked")
    }

    func testRememberedPickGivesWayWhenTheLoginIsNotItsOwner() {
        var selection = ProjectSelection(linked: nil, remembered: "old")
        XCTAssertTrue(selection.notOwner())
        XCTAssertNil(selection.id)
        XCTAssertTrue(selection.choose("owned"))
        XCTAssertEqual(selection.id, "owned")
    }
}
