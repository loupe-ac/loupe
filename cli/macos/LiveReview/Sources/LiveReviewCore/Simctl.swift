import Foundation

public enum Simctl {
    private static let xcrun = "/usr/bin/xcrun"

    public static func bootedDevices() async -> [BootedDevice] {
        guard let json = try? await ProcessRunner.run(executable: xcrun, arguments: ["simctl", "list", "devices", "booted", "-j"]) else { return [] }

        return DeviceMatcher.parseBooted(json)
    }

    /// simctl cannot write an image to stdout, so the shot goes through a temporary file.
    public static func screenshotPNG(udid: String) async -> Data? {
        let file = FileManager.default.temporaryDirectory.appendingPathComponent("live-review-\(UUID().uuidString).png")
        defer { try? FileManager.default.removeItem(at: file) }
        _ = try? await ProcessRunner.run(executable: xcrun, arguments: ["simctl", "io", udid, "screenshot", "--type=png", file.path])
        guard let data = try? Data(contentsOf: file), data.starts(with: [0x89, 0x50, 0x4E, 0x47]) else { return nil }

        return data
    }

    /// The bundle id of the app on screen. It skips the app name, so a pick can read it twice quickly.
    public static func foregroundBundle(udid: String) async -> String? {
        guard let listing = try? await ProcessRunner.run(executable: xcrun, arguments: ["simctl", "spawn", udid, "launchctl", "list"]) else { return nil }
        let running = ForegroundApp.running(launchctl: String(decoding: listing, as: UTF8.self))
        guard !running.isEmpty,
              let ps = try? await ProcessRunner.run(executable: "/bin/ps", arguments: ["-o", "pid=,pri=", "-p", running.map { String($0.pid) }.joined(separator: ",")]) else { return nil }

        return ForegroundApp.pick(running: running, priorities: ForegroundApp.priorities(ps: String(decoding: ps, as: UTF8.self)))
    }

    private static let names = AppNames()

    /// The app with its display name. A name is read once per device and bundle id.
    public static func app(udid: String, bundleId: String?) async -> SimulatorApp? {
        guard let bundleId else { return nil }
        if let name = await names.name(udid: udid, bundleId: bundleId) { return SimulatorApp(bundleId: bundleId, name: name) }
        let apps = try? await ProcessRunner.run(executable: xcrun, arguments: ["simctl", "listapps", udid])
        let name = apps.flatMap { ForegroundApp.displayName(listapps: $0, bundleId: bundleId) }
        if let name { await names.remember(name, udid: udid, bundleId: bundleId) }

        return SimulatorApp(bundleId: bundleId, name: name ?? bundleId)
    }
}

private actor AppNames {
    private var known: [String: String] = [:]

    func name(udid: String, bundleId: String) -> String? { known[udid + " " + bundleId] }

    func remember(_ name: String, udid: String, bundleId: String) { known[udid + " " + bundleId] = name }
}
