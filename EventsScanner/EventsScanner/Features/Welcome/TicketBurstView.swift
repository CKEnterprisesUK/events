import SwiftUI
import UIKit

/// A short (~1.5s), non-interactive post-login flourish: the welcome ticket
/// holds for a beat so it reads, then zooms forward while an emerald
/// brand-colour shockwave opens behind it.
///
/// Presented as a transient overlay by `ObservableRootView` only when the
/// session transitions INTO `.ready` (a fully authenticated, company-scoped
/// session) — never on a failed login, a 2FA prompt, the company picker, or a
/// sign-out. It overlays the already-routed main tabs, so it decorates the
/// transition without delaying navigation.
///
/// Honours Reduce Motion: with the setting on, the ticket is shown statically
/// (no zoom, speed lines, or shockwave) and no haptic fires.
struct TicketBurstView: View {
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    @State private var ticketLaunched = false
    @State private var burstExpanded = false

    // Use the shared brand tokens so the flourish always matches the rest of
    // the app (sign-in backdrop, scan accents, status colours) rather than
    // drifting with its own hardcoded values.
    private let navy = Brand.navy
    private let emerald = Brand.emerald

    var body: some View {
        GeometryReader { geometry in
            ZStack {
                navy.ignoresSafeArea()

                if !reduceMotion {
                    // A circular brand-colour shockwave fills the viewport.
                    Circle()
                        .fill(emerald)
                        .frame(
                            width: max(geometry.size.width, geometry.size.height) * 2.8,
                            height: max(geometry.size.width, geometry.size.height) * 2.8
                        )
                        .scaleEffect(burstExpanded ? 1 : 0.001)
                        .opacity(burstExpanded ? 1 : 0)
                        .position(
                            x: geometry.size.width / 2,
                            y: geometry.size.height / 2
                        )

                    // Speed lines emphasise the forward ticket movement.
                    ForEach(0..<8, id: \.self) { index in
                        Capsule()
                            .fill(.white.opacity(0.65))
                            .frame(width: index.isMultiple(of: 2) ? 48 : 28, height: 3)
                            .offset(
                                x: CGFloat((index % 2 == 0) ? -145 : 145),
                                y: CGFloat(index - 4) * 33
                            )
                            .scaleEffect(x: ticketLaunched ? 3.5 : 0.25, y: 1)
                            .opacity(ticketLaunched ? 0 : 0.8)
                    }
                }

                VStack(spacing: 19) {
                    ZStack {
                        RoundedRectangle(cornerRadius: 28, style: .continuous)
                            .fill(.white)
                            .frame(width: 162, height: 116)
                            .rotationEffect(.degrees(-12))
                            .shadow(color: .black.opacity(0.23), radius: 14, y: 10)

                        Image(systemName: "ticket.fill")
                            .font(.system(size: 83, weight: .semibold))
                            .foregroundStyle(navy)
                            .rotationEffect(.degrees(-12))

                        Image(systemName: "checkmark.circle.fill")
                            .font(.system(size: 48, weight: .bold))
                            .foregroundStyle(emerald, navy)
                            .offset(x: 69, y: 46)
                    }

                    Text("WELCOME")
                        .font(.system(size: 19, weight: .heavy, design: .rounded))
                        .tracking(5)
                        .foregroundStyle(.white)
                }
                .scaleEffect(reduceMotion ? 1 : (ticketLaunched ? 4.5 : 0.72))
                .rotationEffect(.degrees(reduceMotion ? 0 : (ticketLaunched ? 8 : -8)))
                .opacity(reduceMotion ? 1 : (ticketLaunched ? 0 : 1))
            }
            .frame(width: geometry.size.width, height: geometry.size.height)
        }
        .ignoresSafeArea()
        .accessibilityElement(children: .ignore)
        .accessibilityLabel("Welcome to Events Ticketing")
        .onAppear {
            guard !reduceMotion else { return }

            UIImpactFeedbackGenerator(style: .light).impactOccurred()

            // Hold the "WELCOME" ticket on screen for a beat so it reads first,
            // then launch it forward and let the brand shockwave open behind it.
            withAnimation(.spring(response: 0.4, dampingFraction: 0.7).delay(0.6)) {
                ticketLaunched = true
            }
            withAnimation(.easeOut(duration: 0.6).delay(0.75)) {
                burstExpanded = true
            }
        }
    }
}

#if DEBUG
struct TicketBurstView_Previews: PreviewProvider {
    static var previews: some View {
        TicketBurstView()
    }
}
#endif
