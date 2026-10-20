import AppKit
import ApplicationServices
import LiveReviewCore
import SwiftUI

enum Auth: Equatable {
    case checking
    case signedOut
    case waiting(code: String?)
    case noProject
    case notOwner
    case ready
}

enum Mode { case pick, draw }

/// What the owner marked: a frozen screenshot with the element or the strokes drawn on it.
struct Capture {
    var device: BootedDevice
    var app: SimulatorApp?
    var screenTitle: String
    var element: Element?
    var mapper: ScreenMapper
    var png: Data
    var strokes: [[CGPoint]] = []
    var image: RenderedImage
}

final class AppModel: ObservableObject {
    let options: LaunchOptions
    private let client: HelperClient
    private let preference = ProjectPreference()
    private let overlay = CaptureOverlay()
    private var timer: Timer?
    private var hover: Element?
    private var lastMove = Date.distantPast
    private var drawing: [CGPoint]?
    private var freezing = false
    private var placed = false
    private var windows: [Located] = []
    private var deliveries = DeliveryKeeper()
    private var statusInFlight = false
    private var signInShownAt: Date?
    private var freezeGeneration = 0

    @Published var trusted = AXIsProcessTrusted()
    @Published var auth: Auth = .checking
    @Published var authError: String?
    @Published var project: String?
    @Published var projects: [Project] = []
    @Published var destination: Destination = .reviewCard
    @Published var targets: [TargetCard] = []
    @Published var parents: [TargetCard] = []
    @Published var openNotes: [OpenNote] = []
    @Published var located: Located?
    @Published var mode: Mode = .pick { didSet { modeChanged() } }
    @Published var capture: Capture?
    @Published var nothingToPick = false
    @Published var note = ""
    @Published var saving = false
    @Published var message: String?
    @Published var notice: String?

    init(options: LaunchOptions) {
        self.options = options
        client = HelperClient(loupePath: options.loupePath)
        project = options.project ?? preference.project(for: options.folder)
    }

    func start() {
        let prompt = "AXTrustedCheckOptionPrompt" as CFString
        trusted = AXIsProcessTrustedWithOptions([prompt: true] as CFDictionary)
        overlay.onMove = { [weak self] point in self?.moved(to: point) }
        overlay.onMouse = { [weak self] kind, point in self?.handle(kind, at: point) }
        timer = Timer.scheduledTimer(withTimeInterval: 1, repeats: true) { [weak self] _ in self?.tick() }
        Timer.scheduledTimer(withTimeInterval: 0.1, repeats: true) { [weak self] _ in
            self?.overlay.passThrough(NSEvent.modifierFlags.contains(.option))
            self?.followPointer()
        }
        refreshAuth()
    }

    // MARK: Sign-in and project

    func refreshAuth() {
        guard !statusInFlight else { return }
        statusInFlight = true
        let startedAt = Date()
        Task {
            let result = await Result { try await client.status(project: project) }
            await MainActor.run {
                self.statusInFlight = false
                switch result {
                case .success(.ok): self.signedIn()
                case .success(.pending): if case .waiting = self.auth {} else { self.auth = .waiting(code: nil) }
                case .success(.signedOut): self.signedOutAnswer(startedAt: startedAt)
                case .success(.noProject): self.auth = .noProject
                case .success(.notOwner): self.auth = .notOwner; self.project = nil; self.loadProjects()
                case let .failure(error): self.authError = error.localizedDescription
                }
            }
        }
    }

    /// The helper drops a denied or expired sign-in, so a signed-out answer while the panel
    /// waits means the sign-in ended. An answer that started before the sign-in did says nothing.
    private func signedOutAnswer(startedAt: Date) {
        if case .waiting = auth {
            if let shown = signInShownAt, shown > startedAt { return }
            authError = "The sign-in did not finish. Try again."
        }
        auth = .signedOut
    }

    func beginSignIn() {
        authError = nil
        Task {
            do {
                let prompt = try await client.signIn()
                await MainActor.run {
                    self.signInShownAt = Date()
                    self.auth = .waiting(code: prompt.code)
                }
            } catch {
                await MainActor.run { self.authError = error.localizedDescription }
            }
        }
    }

    private func signedIn() {
        auth = .ready
        authError = nil
        if project == nil { loadProjects() } else { loadDestinations() }
    }

    private func loadProjects() {
        Task {
            let list = (try? await client.projects()) ?? []
            await MainActor.run {
                self.projects = list
                if list.count == 1 { self.choose(project: list[0].id) }
            }
        }
    }

    func choose(project id: String) {
        project = id
        preference.remember(id, for: options.folder)
        destination = .reviewCard
        loadDestinations()
    }

    func loadDestinations() {
        guard let project else { return }
        Task {
            let cards = (try? await client.targets(project: project)) ?? []
            let epics = (try? await client.targets(project: project, parentsOnly: true)) ?? []
            await MainActor.run { self.targets = cards; self.parents = epics }
        }
        loadOpenNotes()
    }

    func loadOpenNotes() {
        guard let project, case let .card(id, _) = destination else {
            openNotes = []
            return
        }
        Task {
            let list = (try? await client.notes(project: project, cardId: id)) ?? []
            await MainActor.run { self.openNotes = list }
        }
    }

    func choose(destination next: Destination) {
        destination = next
        loadOpenNotes()
    }

    // MARK: Simulator

    private var reviewing: Bool { trusted && auth == .ready && project != nil }

    private func tick() {
        let now = AXIsProcessTrusted()
        if now != trusted { trusted = now }
        if auth != .ready { refreshAuth() }
        guard reviewing, capture == nil, !freezing else { return }
        Task.detached {
            let devices = await Simctl.bootedDevices()
            let found = DeviceHub.locate(devices: devices)
            await MainActor.run {
                self.windows = found
                self.followPointer(force: true)
            }
        }
    }

    /// Reviews the simulator window under the pointer. A capture in progress keeps its window.
    private func followPointer(force: Bool = false) {
        guard reviewing, capture == nil, !freezing, drawing == nil else { return }
        let index = WindowChooser.choose(
            ids: windows.map(\.device.udid), windows: windows.map(\.window),
            pointer: globalPoint(NSEvent.mouseLocation), current: located?.device.udid
        )
        let next = index.map { windows[$0] }
        let same = next?.device.udid == located?.device.udid && next?.screen == located?.screen && next?.window == located?.window
        guard force || !same else { return }
        if !same {
            located = next
            hover = nil
        }
        if let next { placePanel(beside: next) }
        updateOverlays()
    }

    /// Puts the panel window next to the first Device Hub window it finds, once.
    private func placePanel(beside found: Located) {
        guard !placed, let window = NSApp.windows.first(where: { !($0 is NSPanel) && $0.isVisible }) else { return }
        placed = true
        let hub = appKitRect(found.window)
        let visible = NSScreen.screens.first(where: { $0.frame.intersects(hub) })?.visibleFrame ?? hub
        var x = hub.maxX + 12
        if x + window.frame.width > visible.maxX { x = max(visible.minX, hub.minX - window.frame.width - 12) }
        window.setFrameTopLeftPoint(NSPoint(x: x, y: hub.maxY))
    }

    private func modeChanged() {
        nothingToPick = false
        updateOverlays()
    }

    private func updateOverlays() {
        guard let located, reviewing else {
            overlay.hide()
            return
        }
        if mode == .draw {
            overlay.show(screen: located.screen, highlight: capture?.element?.frame, strokes: (capture?.strokes ?? []) + (drawing.map { [$0] } ?? []))
        } else {
            overlay.show(screen: located.screen, highlight: hover?.frame ?? capture?.element?.frame, strokes: capture?.strokes ?? [])
        }
    }

    private func inScreen(_ point: CGPoint) -> Bool {
        located?.screen.contains(x: Double(point.x), y: Double(point.y)) ?? false
    }

    private func moved(to point: CGPoint?) {
        guard Date().timeIntervalSince(lastMove) > 0.04 else { return }
        lastMove = Date()
        updateHover(at: point)
    }

    private func updateHover(at point: CGPoint?) {
        guard reviewing, mode == .pick, let located else { return }
        guard let point, let node = DeviceHub.element(at: point) else {
            hover = nil
            updateOverlays()
            return
        }
        let element = DeviceHub.read(node, inNavigationBar: false)
        let whole = element.map { $0.frame.width >= located.screen.width * 0.98 && $0.frame.height >= located.screen.height * 0.98 } ?? true
        hover = whole ? nil : element
        updateOverlays()
    }

    private func handle(_ kind: MouseKind, at point: CGPoint) {
        guard reviewing, let located else { return }
        switch mode {
        case .pick:
            if kind == .down { pick(at: point, located: located) }
        case .draw:
            switch kind {
            case .down:
                drawing = [point]
                freezeIfNeeded(located)
            case .drag:
                drawing?.append(point)
                updateOverlays()
            case .up:
                finishStroke()
            }
        }
    }

    private func pick(at point: CGPoint, located: Located) {
        updateHover(at: point)
        guard let element = hover else {
            nothingToPick = true
            freezeGeneration += 1
            freezing = false
            capture = nil
            updateOverlays()
            return
        }
        nothingToPick = false
        pendingStrokes = []
        freeze(located: located, element: element, strokes: [])
    }

    private func freezeIfNeeded(_ located: Located) {
        if capture == nil, !freezing { freeze(located: located, element: nil, strokes: []) }
    }

    private func finishStroke() {
        guard let stroke = drawing, stroke.count >= 2 else {
            drawing = nil
            return
        }
        drawing = nil
        if var current = capture {
            current.strokes.append(stroke)
            if let next = rerender(current) { capture = next } else { message = Self.renderFailed }
            updateOverlays()
        } else {
            pendingStrokes.append(stroke)
        }
    }

    private var pendingStrokes: [[CGPoint]] = []

    private static let renderFailed = "The marked screenshot could not be drawn. Pick or draw again."

    private func rerender(_ capture: Capture) -> Capture? {
        let marks = Marks(
            box: capture.element.map { capture.mapper.pixels($0.frame) },
            strokes: capture.strokes.compactMap { capture.mapper.stroke(from: $0) }
        )
        guard let image = ImageRenderer.render(png: capture.png, marks: marks), !image.jpeg.isEmpty else { return nil }
        var next = capture
        next.image = image

        return next
    }

    private func freeze(located: Located, element: Element?, strokes: [[CGPoint]]) {
        freezeGeneration += 1
        let generation = freezeGeneration
        freezing = true
        Task.detached {
            let png = await Simctl.screenshotPNG(udid: located.device.udid)
            let app = await Simctl.foregroundApp(udid: located.device.udid)
            let title = Self.screenTitle(at: located)
            await MainActor.run {
                guard generation == self.freezeGeneration else { return }
                self.freezing = false
                guard let png, let size = ImageRenderer.size(png: png),
                      let mapper = ScreenMapper(screen: located.screen, imageWidth: size.width, imageHeight: size.height) else {
                    self.message = "The simulator screenshot failed."
                    return
                }
                let made = Capture(device: located.device, app: app, screenTitle: title, element: element, mapper: mapper, png: png,
                                   strokes: strokes + self.pendingStrokes,
                                   image: RenderedImage(jpeg: Data(), width: 0, height: 0))
                self.pendingStrokes = []
                guard let rendered = self.rerender(made) else {
                    self.message = Self.renderFailed
                    self.updateOverlays()
                    return
                }
                self.capture = rendered
                self.message = nil
                self.notice = nil
                self.updateOverlays()
            }
        }
    }

    private static func screenTitle(at located: Located) -> String {
        ElementList.screenTitle(DeviceHub.elements(in: located.group))
    }

    // MARK: Save

    func clearCapture() {
        freezeGeneration += 1
        freezing = false
        capture = nil
        nothingToPick = false
        hover = nil
        pendingStrokes = []
        updateOverlays()
    }

    var canSave: Bool {
        guard let capture, !saving else { return false }

        return Note.canSave(body: note, jpeg: capture.image.jpeg)
    }

    func save() {
        guard let capture, let project, canSave else { return }
        let body = note.trimmingCharacters(in: .whitespacesAndNewlines)
        let element = capture.element.map { e in
            NoteElement(role: e.displayRole, label: e.label.isEmpty ? nil : e.label, identifier: e.identifier.isEmpty ? nil : e.identifier,
                        frame: capture.mapper.relative(e.frame))
        }
        let native = NativeFacts(
            appName: capture.app?.name ?? "Unknown app", bundleId: capture.app?.bundleId ?? "unknown",
            deviceName: capture.device.name, udid: capture.device.udid, osVersion: capture.device.osVersion,
            screenTitle: capture.screenTitle, element: element
        )
        var draft = Note(body: body, destination: destination, native: native, jpeg: capture.image.jpeg)
        guard let id = try? deliveries.id(for: draft) else { return }
        draft.deliveryId = id
        saving = true
        message = nil
        notice = nil
        Task {
            do {
                let result = try await client.save(project: project, note: draft)
                await MainActor.run {
                    self.saving = false
                    self.destination = self.destination.afterSave(result)
                    self.notice = result.notice
                    self.note = ""
                    self.deliveries.reset()
                    self.clearCapture()
                    self.loadOpenNotes()
                }
            } catch {
                await MainActor.run {
                    self.saving = false
                    self.message = error.localizedDescription
                    if let failure = error as? HelperError, failure.code == "signed_out" { self.auth = .signedOut }
                }
            }
        }
    }

    var destinationLabel: String {
        switch destination {
        case let .card(_, label): return label
        case .reviewCard: return "One card for this review"
        case .eachNote(nil): return "A new card for each note"
        case let .eachNote(parentId?): return "A card for each note under \(parents.first { $0.id == parentId }?.label ?? "an epic")"
        }
    }

    var titleText: String {
        located.map { "Live review · \($0.device.name)" } ?? "Live review"
    }
}
