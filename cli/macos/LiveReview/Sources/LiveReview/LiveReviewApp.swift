import AppKit
import LiveReviewCore
import SwiftUI

final class AppDelegate: NSObject, NSApplicationDelegate {
    func applicationDidFinishLaunching(_ notification: Notification) {
        NSApp.setActivationPolicy(.regular)
        NSApp.activate(ignoringOtherApps: true)
    }

    func applicationShouldTerminateAfterLastWindowClosed(_ sender: NSApplication) -> Bool { true }
}

struct RootView: View {
    @StateObject private var model: AppModel

    init(options: LaunchOptions) {
        _model = StateObject(wrappedValue: AppModel(options: options))
    }

    var body: some View {
        PanelView(model: model).onAppear { model.start() }
    }
}

struct MissingLoupeView: View {
    var body: some View {
        Text("Live review starts from the loupe command. Run `loupe review ios` in your project folder.")
            .font(.callout)
            .multilineTextAlignment(.center)
            .padding(16)
            .frame(width: 380)
            .navigationTitle("Live review")
    }
}

@main
struct LiveReviewApp: App {
    @NSApplicationDelegateAdaptor(AppDelegate.self) private var delegate
    private let options = LaunchOptions.parse(Array(CommandLine.arguments.dropFirst()))

    var body: some Scene {
        WindowGroup {
            if let options {
                RootView(options: options)
            } else {
                MissingLoupeView()
            }
        }
        .windowResizability(.contentSize)
    }
}
