import SwiftUI

/// Shared colour tokens. Dynamic colours are defined in Assets.xcassets.
enum Brand {
    static let navy = Color("BrandNavy")
    static let emerald = Color("BrandEmerald")
    static let amber = Color("BrandAmber")
    static let surface = Color("BrandSurface")
    static let danger = Color("BrandDanger")
    static let accent = Color.accentColor
}

/// Branded empty state that respects Dynamic Type and accessibility labels.
struct BrandEmptyState: View {
    let symbol: String
    let title: String
    let message: String

    var body: some View {
        VStack(spacing: 14) {
            Image(systemName: symbol)
                .font(.system(size: 38, weight: .medium))
                .symbolRenderingMode(.hierarchical)
                .foregroundStyle(Brand.emerald)
                .frame(width: 88, height: 88)
                .background(Brand.emerald.opacity(0.10), in: RoundedRectangle(cornerRadius: 24))
                .accessibilityHidden(true)
            Text(title).font(.title3.bold())
            Text(message)
                .font(.callout)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
        }
        .frame(maxWidth: .infinity)
        .padding(24)
        .accessibilityElement(children: .combine)
    }
}
