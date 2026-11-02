import SwiftUI

/// A dedicated "get started" page. Creating and managing events happens in the
/// web app, not on the scanner — this screen explains that clearly and links
/// out to the web. Shown as the Events tab's empty state (no events yet) and
/// reachable from the sign-in screen as "Create an account".
@available(iOS 17.0, *)
struct CreateEventView: View {
    /// Where to send the user to create/manage events. When nil (not yet
    /// loaded, or unauthenticated), a sensible default web URL is used.
    let webDashboardURL: URL?
    var errorMessage: String? = nil

    /// A fallback web destination for the unauthenticated (sign-in) context,
    /// where the events API hasn't been called.
    var fallbackURL: URL? = URL(string: "https://events.ckent.uk")

    private var destination: URL? { webDashboardURL ?? fallbackURL }

    var body: some View {
        ScrollView {
            VStack(spacing: 20) {
                Image("BrandLogo")
                    .resizable()
                    .scaledToFit()
                    .frame(width: 84, height: 84)
                    .clipShape(RoundedRectangle(cornerRadius: 20))
                    .accessibilityHidden(true)

                VStack(spacing: 8) {
                    Text("Start your events")
                        .font(.title2.bold())
                        .multilineTextAlignment(.center)
                    Text("The scanner app checks people in at the door. To create an account and set up, price and publish your events, head to the web app.")
                        .font(.callout)
                        .foregroundStyle(.secondary)
                        .multilineTextAlignment(.center)
                }
                .padding(.horizontal)

                VStack(alignment: .leading, spacing: 14) {
                    step(icon: "person.crop.circle.badge.plus", text: "Create your account online")
                    step(icon: "calendar.badge.plus", text: "Add your event, tickets and pricing")
                    step(icon: "qrcode.viewfinder", text: "Come back here to scan tickets at the door")
                }
                .padding()
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(Brand.surface, in: RoundedRectangle(cornerRadius: 16))

                if let destination {
                    Link(destination: destination) {
                        Text("Create an account & start your events")
                            .fontWeight(.semibold)
                            .frame(maxWidth: .infinity)
                            .padding(.vertical, 14)
                    }
                    .buttonStyle(.borderedProminent)
                    .accessibilityIdentifier("createEvent.web")

                    Text("Opens the web app in your browser.")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }

                if let errorMessage {
                    InlineErrorText(message: errorMessage)
                        .padding(.top, 4)
                }
            }
            .padding(24)
        }
    }

    private func step(icon: String, text: String) -> some View {
        HStack(spacing: 12) {
            Image(systemName: icon)
                .font(.title3)
                .foregroundStyle(Brand.emerald)
                .frame(width: 28)
                .accessibilityHidden(true)
            Text(text)
                .font(.callout)
            Spacer(minLength: 0)
        }
        .accessibilityElement(children: .combine)
    }
}
