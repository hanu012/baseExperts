import 'package:flutter/material.dart';

import '../constants/color_res.dart';
import '../constants/constant.dart';
import 'base_services.dart';
import 'base_text.dart';

/// The shared visual system for the salesman app's home, vendor detail and
/// profile screens.
///
/// Sits beside `base_services.dart` and reuses its [ServiceTokens] palette
/// rather than declaring a second one — the two redesigns are the same
/// design language, and two copies of #7C3AED is how they drift apart.
/// What lives here is the chrome those three screens share and the
/// services/zones screens do not: the branded hero, translucent-on-violet
/// stat tiles, the vendor card, and the grouped settings list.

// ── Hero ──────────────────────────────────────────────────────────────────

/// The violet gradient block every salesman screen opens with. Callers
/// supply their own content; this owns the gradient, the safe area and the
/// fade into the page background.
class SalesmanHero extends StatelessWidget {
  const SalesmanHero({
    super.key,
    required this.child,
    this.bottomPadding,
    this.center = false,
  });

  final Widget child;
  final double? bottomPadding;

  /// Profile centres its content; home and detail are left-aligned.
  final bool center;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [ServiceTokens.heroTop, ServiceTokens.heroMid, ServiceTokens.heroBottom],
          stops: [0.0, 0.48, 1.0],
        ),
      ),
      child: SafeArea(
        bottom: false,
        child: Padding(
          padding: EdgeInsets.fromLTRB(
            20.getSize,
            14.getSize,
            20.getSize,
            bottomPadding ?? 20.getSize,
          ),
          child: Column(
            crossAxisAlignment:
                center ? CrossAxisAlignment.center : CrossAxisAlignment.start,
            children: [child],
          ),
        ),
      ),
    );
  }
}

/// A 36pt translucent square button for the hero's top row.
class HeroIconButton extends StatelessWidget {
  const HeroIconButton({super.key, required this.icon, required this.onTap, this.tooltip});

  final IconData icon;
  final VoidCallback onTap;
  final String? tooltip;

  @override
  Widget build(BuildContext context) {
    final button = Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(11.getSize),
        child: Container(
          height: 36.getSize,
          width: 36.getSize,
          decoration: BoxDecoration(
            color: Colors.white.withValues(alpha: 0.15),
            borderRadius: BorderRadius.circular(11.getSize),
            border: Border.all(color: Colors.white.withValues(alpha: 0.13)),
          ),
          child: Icon(icon, size: 17.getSize, color: Colors.white),
        ),
      ),
    );

    return tooltip == null ? button : Tooltip(message: tooltip!, child: button);
  }
}

/// One of the translucent stat tiles that sit on the violet hero.
class HeroStatTile extends StatelessWidget {
  const HeroStatTile({super.key, required this.value, required this.label});

  final String value;
  final String label;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: EdgeInsets.symmetric(horizontal: 13.getSize, vertical: 11.getSize),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(14.getSize),
        border: Border.all(color: Colors.white.withValues(alpha: 0.14)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          BaseTextDMSans(
            text: value,
            fontSize: 18,
            fontWeight: FontWeight.w800,
            color: Colors.white,
            textAlign: TextAlign.start,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
          ),
          4.heightSpacer,
          BaseTextDMSans(
            text: label,
            fontSize: 10,
            fontWeight: FontWeight.w500,
            color: const Color(0xFFCCFBF1),
            textAlign: TextAlign.start,
            maxLines: 2,
          ),
        ],
      ),
    );
  }
}

/// The three tiles in a row, equal width and equal height.
class HeroStatRow extends StatelessWidget {
  const HeroStatRow({super.key, required this.tiles});

  final List<Widget> tiles;

  @override
  Widget build(BuildContext context) {
    // IntrinsicHeight so a tile whose label wraps to two lines does not
    // leave its neighbours short — and never `stretch` alone, which throws
    // against the unbounded height a scroll view hands down.
    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (var i = 0; i < tiles.length; i++) ...[
            Expanded(child: tiles[i]),
            if (i != tiles.length - 1) 10.widthSpacer,
          ],
        ],
      ),
    );
  }
}

// ── Avatar ────────────────────────────────────────────────────────────────

/// Initials tile. Violet when the subject is "live" (a subscribed vendor),
/// muted grey otherwise — the list's first read of who is paying.
class InitialsAvatar extends StatelessWidget {
  const InitialsAvatar({
    super.key,
    required this.initials,
    this.size,
    this.radius,
    this.active = true,
    this.onWhite = false,
  });

  final String initials;
  final double? size;
  final double? radius;
  final bool active;

  /// The detail hero uses a white tile with violet text, inverting the list.
  final bool onWhite;

  @override
  Widget build(BuildContext context) {
    final box = size ?? 46.getSize;

    return Container(
      height: box,
      width: box,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        gradient: onWhite
            ? null
            : LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: active
                    ? const [ServiceTokens.accentBright, ServiceTokens.accentDeep]
                    : const [ServiceTokens.stroke, ServiceTokens.stroke2],
              ),
        color: onWhite ? Colors.white : null,
        borderRadius: BorderRadius.circular(radius ?? 14.getSize),
      ),
      child: BaseTextDMSans(
        text: initials,
        fontSize: box * 0.37,
        fontWeight: FontWeight.w800,
        color: onWhite
            ? ServiceTokens.accent
            : (active ? Colors.white : ServiceTokens.muted),
      ),
    );
  }
}

// ── Vendor card ───────────────────────────────────────────────────────────

/// A tag pill — "Active" in green, "Not subscribed" in muted grey.
class StatusTag extends StatelessWidget {
  const StatusTag({super.key, required this.label, this.muted = false});

  final String label;
  final bool muted;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: EdgeInsets.symmetric(horizontal: 10.getSize, vertical: 4.getSize),
      decoration: BoxDecoration(
        color: muted
            ? ServiceTokens.muted.withValues(alpha: 0.10)
            : ServiceTokens.green.withValues(alpha: 0.14),
        borderRadius: BorderRadius.circular(8.getSize),
        border: muted ? Border.all(color: ServiceTokens.stroke2) : null,
      ),
      child: BaseTextDMSans(
        text: label,
        fontSize: 10,
        fontWeight: FontWeight.w600,
        color: muted ? ServiceTokens.muted : ServiceTokens.green,
        maxLines: 1,
      ),
    );
  }
}

/// The small plan badge beside a vendor's name.
class PlanPill extends StatelessWidget {
  const PlanPill({super.key, required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: EdgeInsets.symmetric(horizontal: 9.getSize, vertical: 3.getSize),
      decoration: BoxDecoration(
        // The accent tile from the light palette; the old teal-200 text
        // was a dark-theme colour and vanished on a white card.
        color: ServiceTokens.tileFill,
        borderRadius: BorderRadius.circular(20.getSize),
        border: Border.all(color: ServiceTokens.tileBorder),
      ),
      child: BaseTextDMSans(
        text: label,
        fontSize: 10,
        fontWeight: FontWeight.w600,
        color: ServiceTokens.accent,
        maxLines: 1,
      ),
    );
  }
}

/// The segmented quota strip on a vendor card — one segment per sellable
/// resource, filled proportionally to how much of it is used.
class MiniUsageBar extends StatelessWidget {
  const MiniUsageBar({super.key, required this.ratios});

  /// 0..1 per segment.
  final List<double> ratios;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        for (var i = 0; i < ratios.length; i++) ...[
          Expanded(
            child: ClipRRect(
              borderRadius: BorderRadius.circular(3.getSize),
              child: LinearProgressIndicator(
                value: ratios[i],
                minHeight: 4.getSize,
                backgroundColor: ServiceTokens.stroke,
                valueColor: const AlwaysStoppedAnimation<Color>(ServiceTokens.accentBright),
              ),
            ),
          ),
          if (i != ratios.length - 1) 5.widthSpacer,
        ],
      ],
    );
  }
}

/// The card wrapper: violet left rail and brighter border when the vendor
/// is subscribed, flat when they are not.
class VendorCardShell extends StatelessWidget {
  const VendorCardShell({
    super.key,
    required this.child,
    required this.highlighted,
    this.onTap,
  });

  final Widget child;
  final bool highlighted;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: EdgeInsets.only(bottom: 12.getSize),
      decoration: BoxDecoration(
        color: ServiceTokens.card,
        borderRadius: BorderRadius.circular(18.getSize),
        border: Border.all(
          color: highlighted
              ? ServiceTokens.accentBright.withValues(alpha: 0.35)
              : ServiceTokens.stroke,
        ),
      ),
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(18.getSize),
          child: ClipRRect(
            borderRadius: BorderRadius.circular(17.getSize),
            child: IntrinsicHeight(
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (highlighted)
                    Container(
                      width: 4.getSize,
                      decoration: const BoxDecoration(
                        gradient: LinearGradient(
                          begin: Alignment.topCenter,
                          end: Alignment.bottomCenter,
                          colors: [ServiceTokens.accentBright, ServiceTokens.accent],
                        ),
                      ),
                    ),
                  Expanded(
                    child: Padding(
                      padding: EdgeInsets.all(15.getSize),
                      child: child,
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

// ── Panels and settings rows ──────────────────────────────────────────────

/// A titled card. `action` is the small violet text link on the right.
class SalesmanPanel extends StatelessWidget {
  const SalesmanPanel({
    super.key,
    required this.title,
    required this.child,
    this.actionLabel,
    this.onAction,
  });

  final String title;
  final Widget child;
  final String? actionLabel;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: EdgeInsets.only(bottom: 13.getSize),
      padding: EdgeInsets.all(16.getSize),
      decoration: BoxDecoration(
        color: ServiceTokens.card,
        borderRadius: BorderRadius.circular(18.getSize),
        border: Border.all(color: ServiceTokens.stroke),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Flexible(
                child: BaseTextDMSans(
                  text: title,
                  fontSize: 14,
                  fontWeight: FontWeight.w600,
                  color: ServiceTokens.text,
                  textAlign: TextAlign.start,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
              if (actionLabel != null && onAction != null)
                GestureDetector(
                  onTap: onAction,
                  child: BaseTextDMSans(
                    text: actionLabel!,
                    fontSize: 12,
                    fontWeight: FontWeight.w600,
                    color: ServiceTokens.accentBright,
                  ),
                ),
            ],
          ),
          14.heightSpacer,
          child,
        ],
      ),
    );
  }
}

/// A labelled used/max bar — the detail screen's plan usage rows.
class UsageRow extends StatelessWidget {
  const UsageRow({
    super.key,
    required this.icon,
    required this.label,
    required this.used,
    required this.max,
    this.last = false,
  });

  final IconData icon;
  final String label;
  final int used;
  final int max;
  final bool last;

  @override
  Widget build(BuildContext context) {
    final ratio = max == 0 ? 0.0 : (used / max).clamp(0.0, 1.0);
    final full = max > 0 && used >= max;
    final empty = used == 0;

    return Padding(
      padding: EdgeInsets.only(bottom: last ? 0 : 15.getSize),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(icon, size: 15.getSize, color: ServiceTokens.muted),
              7.widthSpacer,
              Expanded(
                child: BaseTextDMSans(
                  text: label,
                  fontSize: 12,
                  fontWeight: FontWeight.w500,
                  color: ServiceTokens.text,
                  textAlign: TextAlign.start,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
              RichText(
                maxLines: 1,
                text: TextSpan(
                  children: [
                    TextSpan(
                      text: '$used',
                      style: TextStyle(
                        fontFamily: FontFamily.poppins,
                        fontSize: 12.getFontSize,
                        fontWeight: FontWeight.w600,
                        color: ServiceTokens.text,
                      ),
                    ),
                    TextSpan(
                      text: ' / $max',
                      style: TextStyle(
                        fontFamily: FontFamily.poppins,
                        fontSize: 12.getFontSize,
                        fontWeight: FontWeight.w600,
                        color: ServiceTokens.muted2,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          7.heightSpacer,
          ClipRRect(
            borderRadius: BorderRadius.circular(6.getSize),
            child: LinearProgressIndicator(
              value: ratio,
              minHeight: 6.getSize,
              backgroundColor: ServiceTokens.card2,
              valueColor: AlwaysStoppedAnimation<Color>(
                empty
                    ? ServiceTokens.stroke2
                    : (full ? const Color(0xFFDC6803) : ServiceTokens.accentBright),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// A labelled group of settings rows — "Account", "Preferences".
class SettingsGroup extends StatelessWidget {
  const SettingsGroup({super.key, required this.title, required this.rows});

  final String title;
  final List<Widget> rows;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(bottom: 18.getSize),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: EdgeInsets.only(left: 4.getSize, bottom: 9.getSize),
            child: BaseTextDMSans(
              text: title,
              fontSize: 12,
              fontWeight: FontWeight.w600,
              color: ServiceTokens.muted2,
              textAlign: TextAlign.start,
            ),
          ),
          Container(
            decoration: BoxDecoration(
              color: ServiceTokens.card,
              borderRadius: BorderRadius.circular(18.getSize),
              border: Border.all(color: ServiceTokens.stroke),
            ),
            child: ClipRRect(
              borderRadius: BorderRadius.circular(17.getSize),
              child: Column(children: rows),
            ),
          ),
        ],
      ),
    );
  }
}

/// One row in a [SettingsGroup]: icon tile, title, optional subtitle, and a
/// trailing widget (chevron, value text, or a switch).
class SettingsRow extends StatelessWidget {
  const SettingsRow({
    super.key,
    required this.icon,
    required this.title,
    this.subtitle,
    this.trailing,
    this.onTap,
    this.first = false,
    this.danger = false,
  });

  final IconData icon;
  final String title;
  final String? subtitle;
  final Widget? trailing;
  final VoidCallback? onTap;
  final bool first;
  final bool danger;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        border: first ? null : Border(top: BorderSide(color: ServiceTokens.stroke)),
      ),
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: onTap,
          child: Padding(
            padding: EdgeInsets.symmetric(horizontal: 15.getSize, vertical: 14.getSize),
            child: Row(
              children: [
                Container(
                  height: 36.getSize,
                  width: 36.getSize,
                  decoration: BoxDecoration(
                    gradient: danger
                        ? null
                        : LinearGradient(
                            begin: Alignment.topLeft,
                            end: Alignment.bottomRight,
                            colors: [
                              ServiceTokens.accentBright.withValues(alpha: 0.25),
                              ServiceTokens.accent.withValues(alpha: 0.08),
                            ],
                          ),
                    color: danger ? ColorRes.errorColor.withValues(alpha: 0.12) : null,
                    borderRadius: BorderRadius.circular(11.getSize),
                    border: Border.all(
                      color: danger
                          ? ColorRes.errorColor.withValues(alpha: 0.25)
                          : ServiceTokens.accentBright.withValues(alpha: 0.20),
                    ),
                  ),
                  child: Icon(
                    icon,
                    size: 17.getSize,
                    color: danger ? ColorRes.errorColor : ServiceTokens.accentBright,
                  ),
                ),
                13.widthSpacer,
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      BaseTextDMSans(
                        text: title,
                        fontSize: 14,
                        fontWeight: FontWeight.w600,
                        color: danger ? ColorRes.errorColor : ServiceTokens.text,
                        textAlign: TextAlign.start,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                      if (subtitle != null) ...[
                        2.heightSpacer,
                        BaseTextDMSans(
                          text: subtitle!,
                          fontSize: 10,
                          color: ServiceTokens.muted,
                          textAlign: TextAlign.start,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ],
                    ],
                  ),
                ),
                if (trailing != null) ...[8.widthSpacer, trailing!],
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// Chevron used as a [SettingsRow] trailing widget.
class RowChevron extends StatelessWidget {
  const RowChevron({super.key});

  @override
  Widget build(BuildContext context) =>
      Icon(Icons.chevron_right, size: 18.getSize, color: ServiceTokens.muted2);
}

/// Right-aligned value text, optionally followed by a chevron.
class RowValue extends StatelessWidget {
  const RowValue({super.key, required this.value, this.withChevron = false});

  final String value;
  final bool withChevron;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        BaseTextDMSans(
          text: value,
          fontSize: 12,
          fontWeight: FontWeight.w600,
          color: ServiceTokens.muted,
          maxLines: 1,
        ),
        if (withChevron) ...[4.widthSpacer, const RowChevron()],
      ],
    );
  }
}

/// The pill switch used for the notification preference.
class SalesmanSwitch extends StatelessWidget {
  const SalesmanSwitch({super.key, required this.value, required this.onChanged});

  final bool value;
  final ValueChanged<bool> onChanged;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: () => onChanged(!value),
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 150),
        height: 24.getSize,
        width: 42.getSize,
        padding: EdgeInsets.all(2.getSize),
        alignment: value ? Alignment.centerRight : Alignment.centerLeft,
        decoration: BoxDecoration(
          color: value ? ServiceTokens.accent : ServiceTokens.stroke2,
          borderRadius: BorderRadius.circular(20.getSize),
        ),
        child: Container(
          height: 20.getSize,
          width: 20.getSize,
          decoration: const BoxDecoration(color: Colors.white, shape: BoxShape.circle),
        ),
      ),
    );
  }
}

/// A full-width outlined button — "Log out", "Delete vendor".
class SalesmanWideButton extends StatelessWidget {
  const SalesmanWideButton({
    super.key,
    required this.label,
    required this.onTap,
    this.icon,
    this.danger = false,
  });

  final String label;
  final VoidCallback onTap;
  final IconData? icon;
  final bool danger;

  @override
  Widget build(BuildContext context) {
    final red = ColorRes.errorColor;

    return Padding(
      padding: EdgeInsets.only(bottom: 11.getSize),
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(12.getSize),
          child: Container(
            width: double.infinity,
            padding: EdgeInsets.symmetric(vertical: 14.getSize),
            decoration: BoxDecoration(
              color: danger ? red.withValues(alpha: 0.10) : ServiceTokens.card,
              borderRadius: BorderRadius.circular(12.getSize),
              border: Border.all(
                color: danger ? red.withValues(alpha: 0.28) : ServiceTokens.stroke,
              ),
            ),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.center,
              mainAxisSize: MainAxisSize.min,
              children: [
                if (icon != null) ...[
                  Icon(icon, size: 17.getSize, color: danger ? red : ServiceTokens.text),
                  9.widthSpacer,
                ],
                Flexible(
                  child: BaseTextDMSans(
                    text: label,
                    fontSize: 12.getFontSize,
                    fontWeight: FontWeight.w600,
                    color: danger ? red : ServiceTokens.text,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
