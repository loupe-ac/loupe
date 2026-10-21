// swift-tools-version:5.9
import PackageDescription

let package = Package(
    name: "LiveReview",
    platforms: [.macOS(.v13)],
    products: [
        .executable(name: "LiveReview", targets: ["LiveReview"]),
    ],
    targets: [
        .target(name: "LiveReviewCore"),
        .executableTarget(name: "LiveReview", dependencies: ["LiveReviewCore"]),
        .testTarget(name: "LiveReviewCoreTests", dependencies: ["LiveReviewCore"]),
    ]
)
