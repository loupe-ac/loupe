/// Says whether the owner works in Device Hub, so the panel shows its marks only then.
public enum HubFocus {
    public static let bundleId = "com.apple.dt.Devices"
    public static let processName = "Device Hub"

    public struct FrontApp: Equatable, Sendable {
        public var pid: Int32
        public var bundleId: String?

        public init(pid: Int32, bundleId: String?) {
            self.pid = pid
            self.bundleId = bundleId
        }
    }

    /// An on-screen window, front to back, as the window server lists it.
    public struct Window: Equatable, Sendable {
        public var pid: Int32
        public var owner: String
        public var layer: Int

        public init(pid: Int32, owner: String, layer: Int) {
            self.pid = pid
            self.owner = owner
            self.layer = layer
        }
    }

    /// The first window that is not the panel's belongs to Device Hub, and no other app is in front.
    /// Device Hub reports a process id of -1 to AppKit, so the front app also matches by bundle id and window owner.
    public static func active(front: FrontApp?, ownPid: Int32, windows: [Window]) -> Bool {
        guard let next = windows.first(where: { $0.layer == 0 && $0.pid != ownPid }), next.owner == processName else { return false }
        guard let front else { return true }

        return front.pid == ownPid || front.pid == next.pid || front.pid == -1 || front.bundleId == bundleId
    }
}
