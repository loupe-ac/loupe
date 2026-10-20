import Foundation

/// `--loupe <path> --dir <folder> [--project <uuid>]`, as `loupe review ios` passes them.
public struct LaunchOptions: Equatable, Sendable {
    public var loupePath: String
    public var folder: String
    public var project: String?

    public init(loupePath: String, folder: String, project: String?) {
        self.loupePath = loupePath
        self.folder = folder
        self.project = project
    }

    public static func parse(_ arguments: [String]) -> LaunchOptions? {
        var values: [String: String] = [:]
        var index = 0
        while index < arguments.count {
            let key = arguments[index]
            if ["--loupe", "--dir", "--project"].contains(key), index + 1 < arguments.count {
                values[key] = arguments[index + 1]
                index += 2
            } else {
                index += 1
            }
        }
        guard let loupe = values["--loupe"], !loupe.isEmpty else { return nil }
        let project = values["--project"].flatMap { $0.isEmpty ? nil : $0 }

        return LaunchOptions(loupePath: loupe, folder: values["--dir"] ?? "", project: project)
    }
}

/// The project the owner picked for a folder, kept between runs.
public struct ProjectPreference {
    private let defaults: UserDefaults

    public init(defaults: UserDefaults = .standard) { self.defaults = defaults }

    private func key(_ folder: String) -> String { "project:" + folder }

    public func project(for folder: String) -> String? { defaults.string(forKey: key(folder)) }

    public func remember(_ project: String, for folder: String) { defaults.set(project, forKey: key(folder)) }
}
