import 'package:flutter/material.dart';

import '../constants/constant.dart';
import 'base_services.dart';
import 'base_text.dart';

/// The vendor app's own visual system.
///
/// Deliberately separate from `base_salesman.dart` even though the two
/// redesigns share a look: the vendor app is its own product with its own
/// screens, and the brief was to keep it that way rather than have the two
/// apps pull on one set of widgets and constrain each other's changes.
///
/// What IS shared is [ServiceTokens] — the palette only. Re-declaring
/// #7C3AED here would be the thing that actually makes the apps drift, and
/// `base_services.dart` already documents why the redesign palette lives
/// apart from [ColorRes].
///
/// Everything in here is chrome: the hero, the cards, the usage rows, the
/// bottom bar. Screen-specific composition stays in the screens.

// ── Hero ──────────────────────────────────────────────────────────────────

/// The violet gradient block every vendor screen opens with.
///
/// Owns the gradient, the decorative rings, the safe area and the fade into
/// the page background, so no screen has to re-derive them.
class VendorHero extends StatelessWidget {
  const VendorHero({super.key, required this.child, this.bottomPadding});

  final Widget child;
  final double? bottomPadding;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [
            ServiceTokens.heroTop,
            ServiceTokens.heroMid,
            ServiceTokens.heroBottom,
          ],
          stops: [0.0, 0.48, 1.0],
        ),
      ),
      child: Stack(
        children: [
          // The faint concentric rings in the top-right corner. Clipped by
          // the Stack so they never paint outside the gradient.
          Positioned(
            right: -60.getSize,
            top: -70.getSize,
            child: _HeroRings(),
          ),
          SafeArea(
            bottom: false,
            child: Padding(
              padding: EdgeInsets.fromLTRB(
                20.getSize,
                14.getSize,
                20.getSize,
                bottomPadding ?? 22.getSize,
              ),
              child: child,
            ),
          ),
        ],
      ),
    );
  }
}

class _HeroRings extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 260.getSize,
      width: 260.getSize,
      child: Stack(
        alignment: Alignment.center,
        children: [
          _ring(260),
          _ring(190),
        ],
      ),
    );
  }

  Widget _ring(double size) {
    return Container(
      height: size.getSize,
      width: size.getSize,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        border: Border.all(color: Colors.white.withValues(alpha: 0.10)),
      ),
    );
  }
}

/// A translucent square button for the hero's top row.
class VendorIconButton extends StatelessWidget {
  const VendorIconButton({
    super.key,
    required this.icon,
    required this.onTap,
    this.tooltip,
  });

  final IconData icon;
  final VoidCallback onTap;
  final String? tooltip;

  @override
  Widget build(BuildContext context) {
    final button = Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(13.getSize),
        child: Container(
          height: 42.getSize,
          width: 42.getSize,
          decoration: BoxDecoration(
            color: Colors.white.withValues(alpha: 0.15),
            borderRadius: BorderRadius.circular(13.getSize),
            border: Border.all(color: Colors.white.withValues(alpha: 0.14)),
          ),
          child: Icon(icon, size: 19.getSize, color: Colors.white),
        ),
      ),
    );

    return tooltip == null ? button : Tooltip(message: tooltip!, child: button);
  }
}

/// The pill-shaped "Sign out" control the subscription screen carries
/// instead of a back chip — there is nothing behind that screen to go back
/// to, so signing out is the only way off it.
class VendorPillButton extends StatelessWidget {
  const VendorPillButton({
    super.key,
    required this.label,
    required this.icon,
    required this.onTap,
  });

  final String label;
  final IconData icon;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(24.getSize),
        child: Container(
          padding: EdgeInsets.symmetric(
            horizontal: 16.getSize,
            vertical: 10.getSize,
          ),
          decoration: BoxDecoration(
            color: Colors.white.withValues(alpha: 0.15),
            borderRadius: BorderRadius.circular(24.getSize),
            border: Border.all(color: Colors.white.withValues(alpha: 0.14)),
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(icon, size: 16.getSize, color: Colors.white),
              8.widthSpacer,
              BaseTextDMSans(
                text: label,
                fontSize: 14,
                fontWeight: FontWeight.w600,
                color: Colors.white,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// The white rounded tile carrying the vendor's photo, or their initials
/// when there is none (or it fails to load).
class VendorAvatarTile extends StatelessWidget {
  const VendorAvatarTile({
    super.key,
    required this.initials,
    this.photoUrl,
    this.size = 44,
    this.fontSize = 14,
  });

  final String initials;
  final String? photoUrl;
  final double size;
  final double fontSize;

  @override
  Widget build(BuildContext context) {
    final corner = BorderRadius.circular((size / 3.6).getSize);
    final photo = photoUrl;

    final text = Center(
      child: BaseTextDMSans(
        text: initials,
        fontSize: fontSize,
        fontWeight: FontWeight.w800,
        color: ServiceTokens.accent,
      ),
    );

    return Container(
      height: size.getSize,
      width: size.getSize,
      decoration: BoxDecoration(color: Colors.white, borderRadius: corner),
      child: photo == null || photo.isEmpty
          ? text
          : ClipRRect(
              borderRadius: corner,
              child: Image.network(
                photo,
                fit: BoxFit.cover,
                width: size.getSize,
                height: size.getSize,
                errorBuilder: (_, _, _) => text,
              ),
            ),
    );
  }
}

/// The hero's identity row: initials tile, "VENDOR" eyebrow over the
/// business name, and whatever actions the screen offers on the right.
class VendorBrandRow extends StatelessWidget {
  const VendorBrandRow({
    super.key,
    required this.initials,
    required this.businessName,
    this.photoUrl,
    this.actions = const [],
  });

  final String initials;
  final String businessName;
  final String? photoUrl;
  final List<Widget> actions;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        VendorAvatarTile(initials: initials, photoUrl: photoUrl),
        12.widthSpacer,
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              BaseTextDMSans(
                text: 'VENDOR',
                fontSize: 10,
                fontWeight: FontWeight.w600,
                letterSpacing: 1.1,
                color: Colors.white.withValues(alpha: 0.72),
                textAlign: TextAlign.start,
              ),
              2.heightSpacer,
              BaseTextDMSans(
                text: businessName,
                fontSize: 16,
                fontWeight: FontWeight.w800,
                color: Colors.white,
                textAlign: TextAlign.start,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
            ],
          ),
        ),
        for (final action in actions) ...[10.widthSpacer, action],
      ],
    );
  }
}

/// The hero's headline block: a small eyebrow line, the big title, and an
/// optional one-line description.
class VendorHeroTitle extends StatelessWidget {
  const VendorHeroTitle({
    super.key,
    this.eyebrow,
    required this.title,
    this.subtitle,
  });

  final String? eyebrow;
  final String title;
  final String? subtitle;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        if (eyebrow != null) ...[
          BaseTextDMSans(
            text: eyebrow!,
            fontSize: 12,
            fontWeight: FontWeight.w600,
            color: Colors.white.withValues(alpha: 0.78),
            textAlign: TextAlign.start,
          ),
          4.heightSpacer,
        ],
        BaseTextDMSans(
          text: title,
          fontSize: 18,
          fontWeight: FontWeight.w600,
          color: Colors.white,
          textAlign: TextAlign.start,
        ),
        if (subtitle != null) ...[
          6.heightSpacer,
          BaseTextDMSans(
            text: subtitle!,
            fontSize: 12,
            color: Colors.white.withValues(alpha: 0.82),
            textAlign: TextAlign.start,
            maxLines: 2,
          ),
        ],
      ],
    );
  }
}

/// "STEP 1 OF 1 · SUBSCRIPTION" — the small uppercase tracker above a
/// flow's headline.
class VendorStepLabel extends StatelessWidget {
  const VendorStepLabel({super.key, required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    return BaseTextDMSans(
      text: text.toUpperCase(),
      fontSize: 12,
      fontWeight: FontWeight.w600,
      letterSpacing: 1.3,
      color: Colors.white.withValues(alpha: 0.80),
      textAlign: TextAlign.start,
    );
  }
}

// ── Surfaces ──────────────────────────────────────────────────────────────

/// A titled card. `trailing` is the small muted meta on the right of the
/// title row ("5 limits", "3 left").
class VendorPanel extends StatelessWidget {
  const VendorPanel({
    super.key,
    this.title,
    this.trailing,
    required this.child,
    this.padding,
  });

  final String? title;
  final Widget? trailing;
  final Widget child;
  final EdgeInsets? padding;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: padding ?? EdgeInsets.all(16.getSize),
      decoration: BoxDecoration(
        color: ServiceTokens.card,
        borderRadius: BorderRadius.circular(18.getSize),
        border: Border.all(color: ServiceTokens.stroke),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          if (title != null) ...[
            Row(
              children: [
                Expanded(
                  child: BaseTextDMSans(
                    text: title!,
                    fontSize: 16,
                    fontWeight: FontWeight.w800,
                    color: ServiceTokens.text,
                    textAlign: TextAlign.start,
                  ),
                ),
                ?trailing,
              ],
            ),
            14.heightSpacer,
          ],
          child,
        ],
      ),
    );
  }
}

/// The rounded violet-tinted square behind a section's icon.
class VendorIconTile extends StatelessWidget {
  const VendorIconTile({super.key, required this.icon, this.size = 40});

  final IconData icon;
  final double size;

  @override
  Widget build(BuildContext context) {
    return Container(
      height: size.getSize,
      width: size.getSize,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: ServiceTokens.accent.withValues(alpha: 0.18),
        borderRadius: BorderRadius.circular((size / 3.3).getSize),
        border: Border.all(color: ServiceTokens.accent.withValues(alpha: 0.28)),
      ),
      child: Icon(icon, size: (size * 0.45).getSize, color: ServiceTokens.accentBright),
    );
  }
}

/// Green count badge — "3 left", "9 left".
class VendorBadge extends StatelessWidget {
  const VendorBadge({super.key, required this.label, this.muted = false});

  final String label;
  final bool muted;

  @override
  Widget build(BuildContext context) {
    final color = muted ? ServiceTokens.muted2 : ServiceTokens.green;

    return Container(
      padding: EdgeInsets.symmetric(horizontal: 11.getSize, vertical: 5.getSize),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.14),
        borderRadius: BorderRadius.circular(20.getSize),
        border: Border.all(color: color.withValues(alpha: 0.30)),
      ),
      child: BaseTextDMSans(
        text: label,
        fontSize: 12,
        fontWeight: FontWeight.w600,
        color: color,
      ),
    );
  }
}

/// A plain pill listing something the vendor has picked.
class VendorChip extends StatelessWidget {
  const VendorChip({super.key, required this.label, this.icon});

  final String label;
  final IconData? icon;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: EdgeInsets.symmetric(horizontal: 14.getSize, vertical: 9.getSize),
      decoration: BoxDecoration(
        color: ServiceTokens.card2,
        borderRadius: BorderRadius.circular(22.getSize),
        border: Border.all(color: ServiceTokens.stroke2),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (icon != null) ...[
            Icon(icon, size: 14.getSize, color: ServiceTokens.muted),
            6.widthSpacer,
          ],
          BaseTextDMSans(
            text: label,
            fontSize: 14,
            fontWeight: FontWeight.w600,
            color: ServiceTokens.text,
          ),
        ],
      ),
    );
  }
}

/// A labelled used/max row with a progress bar — the Plan usage list.
class VendorUsageRow extends StatelessWidget {
  const VendorUsageRow({
    super.key,
    required this.icon,
    required this.label,
    required this.used,
    required this.max,
    this.isLast = false,
  });

  final IconData icon;
  final String label;
  final int used;
  final int max;
  final bool isLast;

  @override
  Widget build(BuildContext context) {
    // A plan with no allowance for a resource is 0/0 — guard the divide
    // rather than render NaN-width.
    final ratio = max == 0 ? 0.0 : (used / max).clamp(0.0, 1.0);

    return Padding(
      padding: EdgeInsets.only(bottom: isLast ? 0 : 18.getSize),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            children: [
              VendorIconTile(icon: icon, size: 38),
              12.widthSpacer,
              Expanded(
                child: BaseTextDMSans(
                  text: label,
                  fontSize: 14,
                  fontWeight: FontWeight.w600,
                  color: ServiceTokens.text,
                  textAlign: TextAlign.start,
                ),
              ),
              RichText(
                text: TextSpan(
                  children: [
                    TextSpan(
                      text: '$used',
                      style: TextStyle(
                        fontFamily: FontFamily.poppins,
                        fontSize: 14.getFontSize,
                        fontWeight: FontWeight.w800,
                        color: ServiceTokens.text,
                      ),
                    ),
                    TextSpan(
                      text: ' / $max',
                      style: TextStyle(
                        fontFamily: FontFamily.poppins,
                        fontSize: 14.getFontSize,
                        fontWeight: FontWeight.w600,
                        color: ServiceTokens.muted,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          10.heightSpacer,
          VendorProgressBar(ratio: ratio),
        ],
      ),
    );
  }
}

/// The thin rounded track used by usage rows and the plan card.
class VendorProgressBar extends StatelessWidget {
  const VendorProgressBar({super.key, required this.ratio, this.onHero = false});

  final double ratio;

  /// Hero-mounted bars sit on violet and need a translucent-white track.
  final bool onHero;

  @override
  Widget build(BuildContext context) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(6.getSize),
      child: LinearProgressIndicator(
        value: ratio.clamp(0.0, 1.0),
        minHeight: 7.getSize,
        backgroundColor:
            onHero ? Colors.white.withValues(alpha: 0.22) : ServiceTokens.card2,
        valueColor: AlwaysStoppedAnimation<Color>(
          onHero ? Colors.white : ServiceTokens.accentBright,
        ),
      ),
    );
  }
}

/// A used/max counter tile — Portfolio's Photos and Videos pair.
class VendorStatTile extends StatelessWidget {
  const VendorStatTile({
    super.key,
    required this.icon,
    required this.label,
    required this.used,
    required this.max,
  });

  final IconData icon;
  final String label;
  final int used;
  final int max;

  @override
  Widget build(BuildContext context) {
    final ratio = max == 0 ? 0.0 : (used / max).clamp(0.0, 1.0);

    return Container(
      padding: EdgeInsets.all(14.getSize),
      decoration: BoxDecoration(
        color: ServiceTokens.card,
        borderRadius: BorderRadius.circular(16.getSize),
        border: Border.all(color: ServiceTokens.stroke),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            children: [
              Icon(icon, size: 16.getSize, color: ServiceTokens.muted),
              7.widthSpacer,
              Flexible(
                child: BaseTextDMSans(
                  text: label,
                  fontSize: 14,
                  fontWeight: FontWeight.w600,
                  color: ServiceTokens.muted,
                  textAlign: TextAlign.start,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
            ],
          ),
          10.heightSpacer,
          RichText(
            text: TextSpan(
              children: [
                TextSpan(
                  text: '$used',
                  style: TextStyle(
                    fontFamily: FontFamily.poppins,
                    fontSize: 18.getFontSize,
                    fontWeight: FontWeight.w800,
                    color: ServiceTokens.text,
                  ),
                ),
                TextSpan(
                  text: ' / $max',
                  style: TextStyle(
                    fontFamily: FontFamily.poppins,
                    fontSize: 14.getFontSize,
                    fontWeight: FontWeight.w600,
                    color: ServiceTokens.muted,
                  ),
                ),
              ],
            ),
          ),
          12.heightSpacer,
          VendorProgressBar(ratio: ratio),
        ],
      ),
    );
  }
}

// ── Buttons ───────────────────────────────────────────────────────────────

/// The full-width violet CTA at the foot of a vendor screen.
///
/// Gradient runs violet-500 → violet-700 with a white label, which
/// CLAUDE.md's contrast rules require: white on violet-400 is 2.7:1 and
/// fails AA, so the gradient must not start lighter than violet-500.
class VendorPrimaryButton extends StatelessWidget {
  const VendorPrimaryButton({
    super.key,
    required this.label,
    required this.onTap,
    this.icon,
    this.enabled = true,
  });

  final String label;
  final VoidCallback onTap;
  final IconData? icon;
  final bool enabled;

  @override
  Widget build(BuildContext context) {
    return Opacity(
      opacity: enabled ? 1 : 0.5,
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: enabled ? onTap : null,
          borderRadius: BorderRadius.circular(12.getSize),
          child: Container(
            height: 54.getSize,
            alignment: Alignment.center,
            decoration: BoxDecoration(
              gradient: const LinearGradient(
                colors: [ServiceTokens.accentBright, ServiceTokens.accentDeep],
              ),
              borderRadius: BorderRadius.circular(12.getSize),
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Flexible(
                  child: BaseTextDMSans(
                    text: label,
                    fontSize: 12.getFontSize,
                    fontWeight: FontWeight.w600,
                    color: Colors.white,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                if (icon != null) ...[
                  9.widthSpacer,
                  Icon(icon, size: 18.getSize, color: Colors.white),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// The quieter outlined twin — "Add video" beside "Add photo".
class VendorSecondaryButton extends StatelessWidget {
  const VendorSecondaryButton({
    super.key,
    required this.label,
    required this.onTap,
    this.icon,
  });

  final String label;
  final VoidCallback onTap;
  final IconData? icon;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(12.getSize),
        child: Container(
          height: 54.getSize,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: ServiceTokens.card,
            borderRadius: BorderRadius.circular(12.getSize),
            border: Border.all(color: ServiceTokens.stroke2),
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              if (icon != null) ...[
                Icon(icon, size: 18.getSize, color: ServiceTokens.text),
                8.widthSpacer,
              ],
              Flexible(
                child: BaseTextDMSans(
                  text: label,
                  fontSize: 12.getFontSize,
                  fontWeight: FontWeight.w600,
                  color: ServiceTokens.text,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

// ── Bottom navigation ─────────────────────────────────────────────────────

/// One destination in [VendorBottomNav].
class VendorNavItem {
  const VendorNavItem({required this.icon, required this.label});

  final IconData icon;

  /// A StringRes key — the bar translates it.
  final String label;
}

/// The vendor app's five-destination bar.
///
/// A bar rather than the top tab strip this screen used to carry: five
/// labels do not fit across a phone as tabs without scrolling, and a
/// destination you have to scroll to find is one nobody visits.
class VendorBottomNav extends StatelessWidget {
  const VendorBottomNav({
    super.key,
    required this.items,
    required this.currentIndex,
    required this.onTap,
  });

  final List<VendorNavItem> items;
  final int currentIndex;
  final ValueChanged<int> onTap;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: ServiceTokens.card,
        border: Border(top: BorderSide(color: ServiceTokens.stroke)),
      ),
      child: SafeArea(
        top: false,
        child: Padding(
          padding: EdgeInsets.symmetric(
            horizontal: 6.getSize,
            vertical: 8.getSize,
          ),
          child: Row(
            children: [
              for (var i = 0; i < items.length; i++)
                Expanded(
                  child: _NavCell(
                    item: items[i],
                    selected: i == currentIndex,
                    onTap: () => onTap(i),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class _NavCell extends StatelessWidget {
  const _NavCell({
    required this.item,
    required this.selected,
    required this.onTap,
  });

  final VendorNavItem item;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    // Accent, not white: the nav bar is a white surface now, so a white
    // selected item would be invisible against it.
    final color = selected ? ServiceTokens.accent : ServiceTokens.navInactive;

    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(14.getSize),
        child: Padding(
          padding: EdgeInsets.symmetric(vertical: 6.getSize),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                padding: EdgeInsets.symmetric(
                  horizontal: 16.getSize,
                  vertical: 5.getSize,
                ),
                decoration: BoxDecoration(
                  color: selected ? ServiceTokens.tileFill : Colors.transparent,
                  borderRadius: BorderRadius.circular(14.getSize),
                ),
                child: Icon(item.icon, size: 20.getSize, color: color),
              ),
              5.heightSpacer,
              BaseTextDMSans(
                text: item.label,
                fontSize: 10,
                fontWeight: selected ? FontWeight.w600 : FontWeight.w500,
                color: color,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

// ── Settings list (Profile) ───────────────────────────────────────────────

/// A labelled group of rows — "Account", "Subscription", "Preferences".
class VendorSettingsGroup extends StatelessWidget {
  const VendorSettingsGroup({
    super.key,
    required this.title,
    required this.rows,
  });

  final String title;
  final List<Widget> rows;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        Padding(
          padding: EdgeInsets.only(left: 4.getSize, bottom: 9.getSize),
          child: BaseTextDMSans(
            text: title,
            fontSize: 12,
            fontWeight: FontWeight.w600,
            color: ServiceTokens.muted,
            textAlign: TextAlign.start,
          ),
        ),
        Container(
          decoration: BoxDecoration(
            color: ServiceTokens.card,
            borderRadius: BorderRadius.circular(18.getSize),
            border: Border.all(color: ServiceTokens.stroke),
          ),
          child: Column(children: rows),
        ),
      ],
    );
  }
}

/// One row in a [VendorSettingsGroup].
class VendorSettingsRow extends StatelessWidget {
  const VendorSettingsRow({
    super.key,
    required this.icon,
    required this.title,
    this.subtitle,
    this.trailing,
    this.onTap,
    this.first = false,
  });

  final IconData icon;
  final String title;
  final String? subtitle;
  final Widget? trailing;
  final VoidCallback? onTap;

  /// The first row in a group skips the top divider.
  final bool first;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        child: Container(
          padding: EdgeInsets.symmetric(
            horizontal: 14.getSize,
            vertical: 13.getSize,
          ),
          decoration: BoxDecoration(
            border: first
                ? null
                : Border(top: BorderSide(color: ServiceTokens.stroke)),
          ),
          child: Row(
            children: [
              VendorIconTile(icon: icon, size: 38),
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
                      color: ServiceTokens.text,
                      textAlign: TextAlign.start,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                    if (subtitle != null) ...[
                      3.heightSpacer,
                      BaseTextDMSans(
                        text: subtitle!,
                        fontSize: 12,
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
    );
  }
}

/// The muted chevron a tappable settings row ends with.
class VendorRowChevron extends StatelessWidget {
  const VendorRowChevron({super.key});

  @override
  Widget build(BuildContext context) {
    return Icon(
      Icons.chevron_right,
      size: 19.getSize,
      color: ServiceTokens.muted2,
    );
  }
}

/// A right-aligned value on a settings row — "English", "Gold".
class VendorRowValue extends StatelessWidget {
  const VendorRowValue({super.key, required this.value, this.withChevron = true});

  final String value;
  final bool withChevron;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        BaseTextDMSans(
          text: value,
          fontSize: 14,
          fontWeight: FontWeight.w600,
          color: ServiceTokens.muted,
        ),
        if (withChevron) ...[4.widthSpacer, const VendorRowChevron()],
      ],
    );
  }
}
