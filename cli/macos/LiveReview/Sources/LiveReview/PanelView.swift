import LiveReviewCore
import SwiftUI

struct PanelView: View {
    @ObservedObject var model: AppModel

    var body: some View {
        Group {
            if !model.trusted {
                AccessView()
            } else if model.auth != .ready {
                SignInView(model: model)
            } else if model.project == nil {
                ProjectPicker(model: model)
            } else if model.located == nil && model.capture == nil {
                Text("Open a booted simulator in Device Hub, in Xcode 27, to review it.")
                    .font(.callout)
                    .multilineTextAlignment(.center)
                    .padding(.vertical, 24)
            } else {
                ReviewView(model: model)
            }
        }
        .padding(16)
        .frame(width: 380)
        .fixedSize(horizontal: false, vertical: true)
        .navigationTitle(model.titleText)
    }
}

struct AccessView: View {
    var body: some View {
        VStack(spacing: 10) {
            Text("Live review needs the Accessibility permission to read the simulator screen.")
                .font(.callout)
                .multilineTextAlignment(.center)
            Button("Open System Settings") {
                if let url = URL(string: "x-apple.systempreferences:com.apple.preference.security?Privacy_Accessibility") {
                    NSWorkspace.shared.open(url)
                }
            }
            Text("Tick Live review in the list. This window updates by itself.").font(.caption).foregroundStyle(.secondary)
        }
    }
}

struct SignInView: View {
    @ObservedObject var model: AppModel

    var body: some View {
        VStack(spacing: 10) {
            switch model.auth {
            case let .waiting(code):
                if let code {
                    Text("Check that the browser page shows this code, then choose Allow.").font(.caption).foregroundStyle(.secondary)
                    Text(code)
                        .font(.system(size: 22, design: .monospaced))
                        .tracking(3)
                        .padding(10)
                        .frame(maxWidth: .infinity)
                        .background(RoundedRectangle(cornerRadius: 6).fill(Color(nsColor: .textBackgroundColor)))
                }
                Text("Waiting for the browser…").font(.caption).foregroundStyle(.secondary)
            case .noProject:
                Text("Create a project in Loupe first, then open this panel again.").font(.callout)
            case .notOwner:
                Text("This login does not cover that project.").font(.callout)
            default:
                Text("Sign in to save notes to Loupe.").font(.callout)
                Button("Sign in with Loupe") { model.beginSignIn() }.buttonStyle(.borderedProminent)
            }
            if let error = model.authError { Text(error).font(.caption).foregroundStyle(.red) }
        }
        .frame(maxWidth: .infinity)
    }
}

struct ProjectPicker: View {
    @ObservedObject var model: AppModel

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("Which project do these notes belong to?").font(.callout)
            ForEach(model.projects) { project in
                Button(project.name) { model.choose(project: project.id) }
            }
            if model.projects.isEmpty { Text("Loading projects…").font(.caption).foregroundStyle(.secondary) }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }
}

struct ReviewView: View {
    @ObservedObject var model: AppModel

    var body: some View {
        VStack(alignment: .leading, spacing: 14) {
            DestinationRow(model: model)
            Picker("", selection: $model.mode) {
                Text("Pick element").tag(Mode.pick)
                Text("Draw").tag(Mode.draw)
            }
            .pickerStyle(.segmented)
            .labelsHidden()
            if model.mode == .pick {
                if model.nothingToPick {
                    NothingToPickView(model: model)
                } else if let capture = model.capture, let element = capture.element {
                    PickedElementView(capture: capture, element: element)
                } else {
                    Text("Click an element in the simulator.").font(.callout).foregroundStyle(.secondary)
                }
            } else {
                Text(model.capture == nil ? "Draw on the simulator screen." : "Draw more, or save the note.")
                    .font(.callout).foregroundStyle(.secondary)
            }
            VStack(alignment: .leading, spacing: 6) {
                Text("Note").font(.caption).fontWeight(.semibold)
                TextEditor(text: $model.note)
                    .font(.body)
                    .frame(minHeight: 64)
                    .disabled(model.saving)
                    .overlay(RoundedRectangle(cornerRadius: 5).stroke(Color.secondary.opacity(0.4)))
            }
            SaveRow(model: model)
            if let message = model.message { Text(message).font(.caption).foregroundStyle(.red) }
            if let notice = model.notice { Text(notice).font(.caption).foregroundStyle(.secondary) }
            OpenNotesView(model: model)
        }
    }
}

struct DestinationRow: View {
    @ObservedObject var model: AppModel

    var body: some View {
        HStack {
            Text("Saves to").font(.caption).foregroundStyle(.secondary)
            Spacer()
            Menu(model.destinationLabel) {
                Button("One card for this review") { model.choose(destination: .reviewCard) }
                Button("A new card for each note") { model.choose(destination: .eachNote(parentId: nil)) }
                if !model.parents.isEmpty {
                    Menu("A card for each note under an epic") {
                        ForEach(model.parents) { epic in
                            Button(epic.label) { model.choose(destination: .eachNote(parentId: epic.id)) }
                        }
                    }
                }
                if !model.targets.isEmpty {
                    Divider()
                    ForEach(model.targets) { card in
                        Button(card.label) { model.choose(destination: .card(id: card.id, label: card.label)) }
                    }
                }
            }
            .menuStyle(.borderlessButton)
            .fixedSize()
            .disabled(model.saving)
        }
    }
}

struct PickedElementView: View {
    let capture: Capture
    let element: Element

    var body: some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(element.label.isEmpty ? element.displayRole : "\(element.displayRole) · “\(element.label)”").fontWeight(.semibold)
            Text("\(capture.app?.name ?? "Unknown app") · \(element.identifier.isEmpty ? "no identifier" : element.identifier)")
                .font(.system(size: 10, design: .monospaced))
                .foregroundStyle(.secondary)
        }
        .padding(8)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(RoundedRectangle(cornerRadius: 6).fill(Color(nsColor: .textBackgroundColor)))
    }
}

struct NothingToPickView: View {
    @ObservedObject var model: AppModel

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("Nothing to pick here.")
            HStack {
                Text("Draw a region instead?").font(.caption).foregroundStyle(.secondary)
                Spacer()
                Button("Draw") { model.mode = .draw }
            }
        }
        .padding(10)
        .frame(maxWidth: .infinity, alignment: .leading)
        .overlay(RoundedRectangle(cornerRadius: 6).stroke(style: StrokeStyle(lineWidth: 1, dash: [4])).foregroundStyle(.secondary))
    }
}

struct SaveRow: View {
    @ObservedObject var model: AppModel

    var body: some View {
        HStack(alignment: .bottom) {
            if let capture = model.capture, let image = NSImage(data: capture.image.jpeg) {
                Image(nsImage: image)
                    .resizable()
                    .scaledToFit()
                    .frame(maxHeight: 96)
                    .clipShape(RoundedRectangle(cornerRadius: 6))
                    .overlay(RoundedRectangle(cornerRadius: 6).stroke(Color.secondary.opacity(0.5)))
            } else {
                Text("Pick or draw first").font(.caption).foregroundStyle(.secondary)
            }
            Spacer()
            Button("Save") { model.save() }
                .buttonStyle(.borderedProminent)
                .disabled(!model.canSave)
                .keyboardShortcut(.defaultAction)
        }
    }
}

struct OpenNotesView: View {
    @ObservedObject var model: AppModel

    var body: some View {
        if case let .card(_, label) = model.destination, !model.openNotes.isEmpty {
            VStack(alignment: .leading, spacing: 4) {
                Text("Open notes on \(label.split(separator: " ").first.map(String.init) ?? label) (\(model.openNotes.count))")
                    .font(.caption).foregroundStyle(.secondary)
                ForEach(model.openNotes) { entry in
                    VStack(alignment: .leading, spacing: 2) {
                        Divider()
                        Text(entry.body).font(.callout)
                        if !entry.detail.isEmpty { Text(entry.detail).font(.caption).foregroundStyle(.secondary) }
                    }
                }
            }
        }
    }
}
