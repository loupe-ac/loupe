import Foundation

public struct HelperError: Error, Equatable, LocalizedError, Sendable {
    public var code: String
    public var message: String

    public init(code: String, message: String) {
        self.code = code
        self.message = message
    }

    public var errorDescription: String? { message }
}

public enum SignInState: String, Sendable {
    case ok, pending, signedOut, noProject, notOwner
}

public struct SignInPrompt: Equatable, Sendable {
    public var code: String
    public var url: String
}

public struct Project: Equatable, Sendable, Identifiable {
    public var id: String
    public var name: String
}

public struct TargetCard: Equatable, Sendable, Identifiable {
    public var id: String
    public var number: Int
    public var title: String
    public var label: String { "#\(number) \(title)" }
}

public struct OpenNote: Equatable, Sendable, Identifiable {
    public var id: String
    public var body: String
    public var detail: String
    public var cardLabel: String?
}

public struct SaveResult: Equatable, Sendable {
    public var commentId: String
    public var cardId: String
    public var number: Int
    public var label: String
    public var url: String
}

/// Runs the `loupe` binary for every request to Loupe. Argument lists only, no shell.
public struct HelperClient: Sendable {
    public typealias Runner = @Sendable (_ arguments: [String], _ stdin: Data?) async throws -> Data

    private let run: Runner

    public init(run: @escaping Runner) { self.run = run }

    public init(loupePath: String) {
        self.init(run: { arguments, stdin in
            try await ProcessRunner.run(executable: loupePath, arguments: ["review"] + arguments, stdin: stdin)
        })
    }

    private func object(_ arguments: [String], stdin: Data? = nil) async throws -> [String: Any] {
        let output = try await run(arguments, stdin)
        guard let line = output.split(separator: UInt8(ascii: "\n")).last,
              let parsed = try? JSONSerialization.jsonObject(with: Data(line)) as? [String: Any] else {
            throw HelperError(code: "invalid_output", message: "The loupe command printed no JSON object.")
        }
        if let code = parsed["error"] as? String {
            throw HelperError(code: code, message: (parsed["message"] as? String) ?? code)
        }

        return parsed
    }

    public func status(project: String?) async throws -> SignInState {
        let args = ["status"] + (project.map { ["--project", $0] } ?? [])
        let state = try await object(args)["state"] as? String
        guard let state, let value = SignInState(rawValue: state) else {
            throw HelperError(code: "invalid_output", message: "Unknown sign-in state.")
        }

        return value
    }

    public func signIn() async throws -> SignInPrompt {
        let answer = try await object(["sign-in"])
        guard let code = answer["code"] as? String, let url = answer["url"] as? String else {
            throw HelperError(code: "invalid_output", message: "The sign-in answer has no code.")
        }

        return SignInPrompt(code: code, url: url)
    }

    public func projects() async throws -> [Project] {
        let list = try await object(["projects"])["projects"] as? [[String: Any]] ?? []

        return list.compactMap { entry in
            guard let id = entry["id"] as? String, let name = entry["name"] as? String else { return nil }
            return Project(id: id, name: name)
        }
    }

    public func targets(project: String, query: String = "", parentsOnly: Bool = false) async throws -> [TargetCard] {
        var args = ["targets", "--project", project]
        if query != "" { args += ["--query", query] }
        if parentsOnly { args.append("--parent") }
        let list = try await object(args)["cards"] as? [[String: Any]] ?? []

        return list.compactMap { entry in
            guard let id = entry["cardId"] as? String, let number = entry["number"] as? Int, let title = entry["title"] as? String else { return nil }
            return TargetCard(id: id, number: number, title: title)
        }
    }

    public func notes(project: String, cardId: String) async throws -> [OpenNote] {
        let answer = try await object(["notes", "--project", project, "--scope", "card:\(cardId)"])
        let comments = (answer["notes"] as? [String: Any])?["comments"] as? [[String: Any]] ?? []

        return comments.compactMap { entry in
            guard let id = entry["id"] as? String, let body = entry["body"] as? String else { return nil }
            let native = entry["native"] as? [String: Any]
            let element = native?["element"] as? [String: Any]
            let role = element?["role"] as? String
            let name = (element?["identifier"] as? String).flatMap { $0.isEmpty ? nil : $0 } ?? (element?["label"] as? String)
            let detail = [role, name].compactMap { $0 }.joined(separator: " · ")
            let link = entry["link"] as? [String: Any]

            return OpenNote(id: id, body: body, detail: detail, cardLabel: link?["label"] as? String)
        }
    }

    public func save(project: String, note: Note) async throws -> SaveResult {
        let answer = try await object(["save", "--project", project], stdin: try note.json())
        guard let commentId = answer["commentId"] as? String, let cardId = answer["cardId"] as? String,
              let number = answer["number"] as? Int, let label = answer["label"] as? String else {
            throw HelperError(code: "invalid_output", message: "The save answer is incomplete.")
        }

        return SaveResult(commentId: commentId, cardId: cardId, number: number, label: label, url: (answer["url"] as? String) ?? "")
    }
}

public enum ProcessRunner {
    /// Runs a program with an argument list and returns its stdout. A non-zero exit is
    /// not an error here: the review helpers print their error object on stdout.
    public static func run(executable: String, arguments: [String], stdin: Data? = nil) async throws -> Data {
        try await withCheckedThrowingContinuation { continuation in
            DispatchQueue.global().async {
                let process = Process()
                process.executableURL = URL(fileURLWithPath: executable)
                process.arguments = arguments
                let out = Pipe()
                process.standardOutput = out
                process.standardError = FileHandle.nullDevice
                let input = Pipe()
                process.standardInput = stdin == nil ? FileHandle.nullDevice : input
                do {
                    try process.run()
                } catch {
                    continuation.resume(throwing: HelperError(code: "launch_failed", message: "Cannot start \(executable)."))
                    return
                }
                if let stdin {
                    // A child that exits early must not kill the panel with SIGPIPE. The failed
                    // write is ignored, and the error object on stdout still reaches the caller.
                    _ = fcntl(input.fileHandleForWriting.fileDescriptor, F_SETNOSIGPIPE, 1)
                    DispatchQueue.global().async {
                        try? input.fileHandleForWriting.write(contentsOf: stdin)
                        try? input.fileHandleForWriting.close()
                    }
                }
                let data = out.fileHandleForReading.readDataToEndOfFile()
                process.waitUntilExit()
                continuation.resume(returning: data)
            }
        }
    }
}
