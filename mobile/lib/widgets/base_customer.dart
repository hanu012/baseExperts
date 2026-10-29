import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../constants/color_res.dart';
import '../constants/constant.dart';
import 'base_services.dart';
import 'base_text.dart';

/// The customer app's own visual system.
///
/// Separate from `base_vendor.dart` for the same reason that one is
/// separate from `base_salesman.dart`: these are three products that
/// happen to share a look today. Folding them into one widget set would
/// mean a change asked for in one app silently reshaping the other two.
///
/// What IS shared is [ServiceTokens] — the palette only. Re-declaring
/// #7C3AED in a third file is the thing that would actually make the
/// apps drift apart.
///
/// Everything here is chrome: the hero, cards, rows, the bottom bar.
/// Screen-specific composition stays in the screens.

// ── Hero ──────────────────────────────────────────────────────────────────

/// The violet gradient block the customer screens open with.
///
/// Carries the faint concentric rings from the designs. They are drawn
/// with [CustomPaint] rather than shipped as an image so they scale to
/// any header height without a second asset to keep in sync.
class CustomerHero extends StatelessWidget {
  const CustomerHero({
    super.key,
    required this.child,
    this.bottomPadding,
    this.topPadding,
  });

  final Widget child;
  final double? bottomPadding;
  final double? topPadding;

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
        ),
      ),
      child: CustomPaint(
        painter: _HeroRingsPainter(),
        child: SafeArea(
          bottom: false,
          child: Padding(
            padding: EdgeInsets.fromLTRB(
              16.getSize,
              topPadding ?? 10.getSize,
              16.getSize,
              bottomPadding ?? 22.getSize,
            ),
            child: child,
          ),
        ),
      ),
    );
  }
}

/// The two faint rings in the header's top-right corner.
class _HeroRingsPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.2
      ..color = Colors.white.withValues(alpha: 0.10);

    final centre = Offset(size.width * 0.86, size.height * 0.16);

    canvas.drawCircle(centre, size.width * 0.30, paint);
    canvas.drawCircle(centre, size.width * 0.46, paint);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

// ── Header controls ───────────────────────────────────────────────────────

/// A translucent square icon button, used for back and for the header's
/// trailing actions.
class CustomerIconButton extends StatelessWidget {
  const CustomerIconButton({
    super.key,
    required this.icon,
    required this.onTap,
    this.tooltip,
    this.size,
    this.fillHeight = false,
  });

  final IconData icon;
  final VoidCallback onTap;
  final String? tooltip;
  final double? size;

  /// Takes its height from the row instead of being square — so it can
  /// match a neighbour (the location pill) whose height is set by its
  /// own content rather than by a number both would have to repeat.
  /// The caller must give the row a bounded height, e.g. IntrinsicHeight.
  final bool fillHeight;

  @override
  Widget build(BuildContext context) {
    final dimension = size ?? 42.getSize;

    final button = Material(
      color: Colors.white.withValues(alpha: 0.16),
      borderRadius: BorderRadius.circular(13.getSize),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(13.getSize),
        child: SizedBox(
          height: fillHeight ? null : dimension,
          width: dimension,
          child: Icon(icon, color: Colors.white, size: 21.getSize),
        ),
      ),
    );

    return tooltip == null ? button : Tooltip(message: tooltip!, child: button);
  }
}

/// The header's location pill: a pin, the current place, and a chevron
/// that says it can be changed.
class CustomerLocationPill extends StatelessWidget {
  const CustomerLocationPill({
    super.key,
    required this.label,
    required this.place,
    required this.onTap,
  });

  /// Small caps line above the place, e.g. "YOUR LOCATION".
  final String label;

  /// The place itself, or a prompt when none is set yet.
  final String place;

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Colors.white.withValues(alpha: 0.16),
      borderRadius: BorderRadius.circular(14.getSize),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(14.getSize),
        child: Padding(
          padding: EdgeInsets.fromLTRB(
            10.getSize,
            8.getSize,
            12.getSize,
            8.getSize,
          ),
          // Fills whatever width the caller gives it, with the chevron
          // pinned to the far edge — the text column flexes rather than
          // the pill shrinking to fit its contents.
          child: Row(
            children: [
              Container(
                height: 28.getSize,
                width: 28.getSize,
                decoration: BoxDecoration(
                  color: Colors.white.withValues(alpha: 0.20),
                  borderRadius: BorderRadius.circular(9.getSize),
                ),
                child: Icon(
                  Icons.location_on_outlined,
                  color: Colors.white,
                  size: 16.getSize,
                ),
              ),
              10.widthSpacer,
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    BaseTextDMSans(
                      text: label.toUpperCase(),
                      fontSize: 10,
                      fontWeight: FontWeight.w600,
                      letterSpacing: 0.8,
                      color: Colors.white.withValues(alpha: 0.78),
                      textAlign: TextAlign.start,
                    ),
                    2.heightSpacer,
                    BaseTextDMSans(
                      text: place,
                      fontSize: 14,
                      fontWeight: FontWeight.w600,
                      color: Colors.white,
                      textAlign: TextAlign.start,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ],
                ),
              ),
              4.widthSpacer,
              Icon(
                Icons.keyboard_arrow_down_rounded,
                color: Colors.white,
                size: 19.getSize,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// The dark search field that sits over the header's bottom edge.
///
/// Rendered as a button rather than a live field where the screen routes
/// elsewhere on tap — a text cursor that appears and is immediately
/// replaced by another screen is a worse affordance than no cursor.
class CustomerSearchBar extends StatelessWidget {
  const CustomerSearchBar({
    super.key,
    required this.hint,
    required this.onTap,
  });

  final String hint;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: ServiceTokens.card,
      borderRadius: BorderRadius.circular(15.getSize),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(15.getSize),
        child: Container(
          padding: EdgeInsets.symmetric(
            horizontal: 15.getSize,
            vertical: 15.getSize,
          ),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(15.getSize),
            border: Border.all(color: ServiceTokens.stroke),
            boxShadow: ServiceTokens.searchShadow,
          ),
          child: Row(
            children: [
              Icon(
                Icons.search,
                color: ServiceTokens.muted2,
                size: 20.getSize,
              ),
              11.widthSpacer,
              Expanded(
                child: BaseTextDMSans(
                  text: hint,
                  fontSize: 14,
                  color: ServiceTokens.muted2,
                  textAlign: TextAlign.start,
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

// ── Section furniture ─────────────────────────────────────────────────────

/// A section title with an optional trailing "See all".
class CustomerSectionHeader extends StatelessWidget {
  const CustomerSectionHeader({
    super.key,
    required this.title,
    this.actionLabel,
    this.onAction,
  });

  final String title;
  final String? actionLabel;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Expanded(
          child: BaseTextDMSans(
            text: title,
            fontSize: 16,
            fontWeight: FontWeight.w800,
            color: ServiceTokens.text,
            textAlign: TextAlign.start,
          ),
        ),
        if (actionLabel != null && onAction != null)
          GestureDetector(
            onTap: onAction,
            behavior: HitTestBehavior.opaque,
            child: Padding(
              padding: EdgeInsets.only(left: 10.getSize),
              child: BaseTextDMSans(
                text: actionLabel!,
                fontSize: 14,
                fontWeight: FontWeight.w600,
                color: ServiceTokens.accentBright,
              ),
            ),
          ),
      ],
    );
  }
}

/// A small caps label above a group of rows ("Account", "Preferences").
class CustomerGroupLabel extends StatelessWidget {
  const CustomerGroupLabel({super.key, required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    return BaseTextDMSans(
      text: text,
      fontSize: 12,
      fontWeight: FontWeight.w600,
      color: ServiceTokens.muted,
      textAlign: TextAlign.start,
    );
  }
}

/// The rounded violet square that fronts most rows and tiles.
class CustomerIconTile extends StatelessWidget {
  const CustomerIconTile({
    super.key,
    required this.icon,
    this.size,
    this.iconSize,
    this.background,
    this.foreground,
  });

  final IconData icon;
  final double? size;
  final double? iconSize;
  final Color? background;
  final Color? foreground;

  @override
  Widget build(BuildContext context) {
    final dimension = (size ?? 42).getSize;

    return Container(
      height: dimension,
      width: dimension,
      decoration: BoxDecoration(
        color: background ?? ServiceTokens.accent.withValues(alpha: 0.20),
        borderRadius: BorderRadius.circular(dimension * 0.30),
        border: Border.all(color: ServiceTokens.stroke2),
      ),
      child: Icon(
        icon,
        color: foreground ?? ServiceTokens.accentBright,
        size: iconSize?.getSize ?? dimension * 0.46,
      ),
    );
  }
}

// ── Grid and list items ───────────────────────────────────────────────────

/// One category in the home grid: icon over a name.
class CustomerCategoryTile extends StatelessWidget {
  const CustomerCategoryTile({
    super.key,
    required this.icon,
    required this.name,
    required this.onTap,
  });

  final IconData icon;
  final String name;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: ServiceTokens.card,
      borderRadius: BorderRadius.circular(16.getSize),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(16.getSize),
        child: Container(
          padding: EdgeInsets.symmetric(vertical: 14.getSize, horizontal: 8.getSize),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(16.getSize),
            border: Border.all(color: ServiceTokens.stroke),
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              CustomerIconTile(icon: icon, size: 44),
              10.heightSpacer,
              BaseTextDMSans(
                text: name,
                fontSize: 12,
                fontWeight: FontWeight.w600,
                color: ServiceTokens.text,
                textAlign: TextAlign.center,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// A popular-service card in the horizontal rail: icon, name, parent
/// category underneath.
class CustomerServiceCard extends StatelessWidget {
  const CustomerServiceCard({
    super.key,
    required this.icon,
    required this.name,
    required this.categoryName,
    required this.onTap,
  });

  final IconData icon;
  final String name;
  final String categoryName;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: 170.getSize,
      child: Material(
        color: ServiceTokens.card,
        borderRadius: BorderRadius.circular(16.getSize),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(16.getSize),
          child: Container(
            padding: EdgeInsets.all(14.getSize),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(16.getSize),
              border: Border.all(color: ServiceTokens.stroke),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                CustomerIconTile(icon: icon, size: 40),
                12.heightSpacer,
                BaseTextDMSans(
                  text: name,
                  fontSize: 14,
                  fontWeight: FontWeight.w600,
                  color: ServiceTokens.text,
                  textAlign: TextAlign.start,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
                4.heightSpacer,
                BaseTextDMSans(
                  text: categoryName,
                  fontSize: 12,
                  color: ServiceTokens.muted,
                  textAlign: TextAlign.start,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// A vendor row in "Vendors near you": avatar, name, a badge, chevron.
class CustomerVendorRow extends StatelessWidget {
  const CustomerVendorRow({
    super.key,
    required this.name,
    required this.onTap,
    this.badge,
    this.rating,
    this.ratingCount,
    this.imageUrl,
  });

  final String name;
  final VoidCallback onTap;

  /// Shown when the vendor has no ratings yet — "New" in the design.
  final String? badge;

  final double? rating;
  final int? ratingCount;
  final String? imageUrl;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: ServiceTokens.card,
      borderRadius: BorderRadius.circular(16.getSize),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(16.getSize),
        child: Container(
          padding: EdgeInsets.all(12.getSize),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(16.getSize),
            border: Border.all(color: ServiceTokens.stroke),
          ),
          child: Row(
            children: [
              avatar(),
              12.widthSpacer,
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    BaseTextDMSans(
                      text: name,
                      fontSize: 14,
                      fontWeight: FontWeight.w600,
                      color: ServiceTokens.text,
                      textAlign: TextAlign.start,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                    7.heightSpacer,
                    subtitle(),
                  ],
                ),
              ),
              8.widthSpacer,
              Icon(
                Icons.chevron_right,
                color: ServiceTokens.muted2,
                size: 20.getSize,
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget avatar() {
    final url = imageUrl;

    return ClipRRect(
      borderRadius: BorderRadius.circular(13.getSize),
      child: SizedBox(
        height: 46.getSize,
        width: 46.getSize,
        child: url == null || url.isEmpty
            ? const CustomerIconTile(icon: Icons.storefront_outlined, size: 46)
            : Image.network(
                url,
                fit: BoxFit.cover,
                errorBuilder: (_, _, _) => const CustomerIconTile(
                  icon: Icons.storefront_outlined,
                  size: 46,
                ),
              ),
      ),
    );
  }

  /// A rating once there is one, otherwise the "New" badge — a vendor
  /// with no reviews showing "0.0" reads as a bad vendor rather than a
  /// new one.
  Widget subtitle() {
    final value = rating;

    if (value == null || value <= 0) {
      return CustomerBadge(text: badge ?? '');
    }

    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(Icons.star_rounded, color: ServiceTokens.accentBright, size: 15.getSize),
        4.widthSpacer,
        BaseTextDMSans(
          text: value.toStringAsFixed(1),
          fontSize: 12,
          fontWeight: FontWeight.w600,
          color: ServiceTokens.text,
        ),
        if (ratingCount != null && ratingCount! > 0) ...[
          5.widthSpacer,
          BaseTextDMSans(
            text: '($ratingCount)',
            fontSize: 12,
            color: ServiceTokens.muted,
          ),
        ],
      ],
    );
  }
}

/// A small pill, used for "New" on a vendor with no ratings.
class CustomerBadge extends StatelessWidget {
  const CustomerBadge({super.key, required this.text, this.color});

  final String text;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final tone = color ?? ServiceTokens.accentBright;

    return Container(
      padding: EdgeInsets.symmetric(horizontal: 9.getSize, vertical: 3.getSize),
      decoration: BoxDecoration(
        color: tone.withValues(alpha: 0.16),
        borderRadius: BorderRadius.circular(8.getSize),
        border: Border.all(color: tone.withValues(alpha: 0.32)),
      ),
      child: BaseTextDMSans(
        text: text,
        fontSize: 10,
        fontWeight: FontWeight.w600,
        color: tone,
      ),
    );
  }
}

// ── Settings rows ─────────────────────────────────────────────────────────

/// A rounded group that its rows sit inside, with hairlines between.
class CustomerGroup extends StatelessWidget {
  const CustomerGroup({super.key, required this.children});

  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: ServiceTokens.card,
        borderRadius: BorderRadius.circular(16.getSize),
        border: Border.all(color: ServiceTokens.stroke),
      ),
      child: Column(
        children: [
          for (var i = 0; i < children.length; i++) ...[
            children[i],
            if (i != children.length - 1)
              Divider(
                height: 1,
                thickness: 1,
                indent: 62.getSize,
                color: ServiceTokens.stroke,
              ),
          ],
        ],
      ),
    );
  }
}

/// One row inside a [CustomerGroup]: icon, title, optional subtitle, and
/// either a chevron, a value, or a supplied trailing widget.
class CustomerRow extends StatelessWidget {
  const CustomerRow({
    super.key,
    required this.icon,
    required this.title,
    this.subtitle,
    this.value,
    this.trailing,
    this.onTap,
    this.danger = false,
  });

  final IconData icon;
  final String title;
  final String? subtitle;

  /// Right-aligned text, e.g. the chosen language.
  final String? value;

  /// Replaces the chevron entirely — a switch, for instance.
  final Widget? trailing;

  final VoidCallback? onTap;

  /// Paints the row red, for destructive entries.
  final bool danger;

  @override
  Widget build(BuildContext context) {
    final tone = danger ? ColorRes.errorColor : ServiceTokens.text;

    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: EdgeInsets.symmetric(
            horizontal: 12.getSize,
            vertical: 12.getSize,
          ),
          child: Row(
            children: [
              CustomerIconTile(
                icon: icon,
                size: 38,
                foreground: danger ? ColorRes.errorColor : null,
                background: danger
                    ? ColorRes.errorColor.withValues(alpha: 0.14)
                    : null,
              ),
              12.widthSpacer,
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    BaseTextDMSans(
                      text: title,
                      fontSize: 14,
                      fontWeight: FontWeight.w600,
                      color: tone,
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
              if (trailing != null)
                trailing!
              else ...[
                if (value != null) ...[
                  BaseTextDMSans(
                    text: value!,
                    fontSize: 12,
                    color: ServiceTokens.muted,
                  ),
                  6.widthSpacer,
                ],
                Icon(
                  Icons.chevron_right,
                  color: ServiceTokens.muted2,
                  size: 20.getSize,
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

// ── Buttons ───────────────────────────────────────────────────────────────

/// The full-width violet gradient button.
class CustomerPrimaryButton extends StatelessWidget {
  const CustomerPrimaryButton({
    super.key,
    required this.label,
    required this.onTap,
    this.enabled = true,
    this.icon,
  });

  final String label;
  final VoidCallback onTap;
  final bool enabled;
  final IconData? icon;

  @override
  Widget build(BuildContext context) {
    return Opacity(
      // Dimmed rather than greyed: the button keeps its shape so the
      // layout does not shift as it enables.
      opacity: enabled ? 1 : 0.45,
      child: Material(
        borderRadius: BorderRadius.circular(12.getSize),
        child: InkWell(
          onTap: enabled ? onTap : null,
          borderRadius: BorderRadius.circular(12.getSize),
          child: Ink(
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(12.getSize),
              gradient: const LinearGradient(
                colors: [ServiceTokens.accentBright, ServiceTokens.accent],
              ),
            ),
            child: Container(
              height: 54.getSize,
              alignment: Alignment.center,
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  if (icon != null) ...[
                    Icon(icon, color: Colors.white, size: 19.getSize),
                    9.widthSpacer,
                  ],
                  BaseTextDMSans(
                    text: label,
                    fontSize: 12.getFontSize,
                    fontWeight: FontWeight.w800,
                    // White, not dark ink — 4.2:1 on violet-500, which is
                    // the contrast rule CLAUDE.md pins down.
                    color: Colors.white,
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

/// A bordered, transparent button for the secondary action under it.
class CustomerSecondaryButton extends StatelessWidget {
  const CustomerSecondaryButton({
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
    final tone = danger ? ColorRes.errorColor : ServiceTokens.text;

    return Material(
      color: danger
          ? ColorRes.errorColor.withValues(alpha: 0.08)
          : ServiceTokens.card,
      borderRadius: BorderRadius.circular(12.getSize),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(12.getSize),
        child: Container(
          height: 54.getSize,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(12.getSize),
            border: Border.all(
              color: danger
                  ? ColorRes.errorColor.withValues(alpha: 0.38)
                  : ServiceTokens.stroke,
            ),
          ),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              if (icon != null) ...[
                Icon(icon, color: tone, size: 18.getSize),
                9.widthSpacer,
              ],
              BaseTextDMSans(
                text: label,
                fontSize: 12.getFontSize,
                fontWeight: FontWeight.w600,
                color: tone,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

// ── Bottom bar ────────────────────────────────────────────────────────────

/// Home / Favourites / Profile.
class CustomerBottomNav extends StatelessWidget {
  const CustomerBottomNav({
    super.key,
    required this.currentIndex,
    required this.onTap,
    required this.items,
  });

  final int currentIndex;
  final ValueChanged<int> onTap;
  final List<CustomerNavItem> items;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: const BoxDecoration(
        color: ServiceTokens.card,
        border: Border(top: BorderSide(color: ServiceTokens.stroke)),
      ),
      child: SafeArea(
        top: false,
        child: Padding(
          padding: EdgeInsets.symmetric(vertical: 7.getSize),
          child: Row(
            children: [
              for (var i = 0; i < items.length; i++)
                Expanded(
                  child: _NavButton(
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

class CustomerNavItem {
  const CustomerNavItem({required this.icon, required this.label});

  final IconData icon;
  final String label;
}

class _NavButton extends StatelessWidget {
  const _NavButton({
    required this.item,
    required this.selected,
    required this.onTap,
  });

  final CustomerNavItem item;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(14.getSize),
      child: Padding(
        padding: EdgeInsets.symmetric(vertical: 5.getSize),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              padding: EdgeInsets.symmetric(
                horizontal: 18.getSize,
                vertical: 5.getSize,
              ),
              decoration: BoxDecoration(
                color: selected ? ServiceTokens.tileFill : Colors.transparent,
                borderRadius: BorderRadius.circular(14.getSize),
              ),
              child: Icon(
                item.icon,
                size: 21.getSize,
                color: selected ? ServiceTokens.accent : ServiceTokens.navInactive,
              ),
            ),
            5.heightSpacer,
            BaseTextDMSans(
              text: item.label,
              fontSize: 12,
              fontWeight: selected ? FontWeight.w600 : FontWeight.w500,
              color: selected ? ServiceTokens.accent : ServiceTokens.navInactive,
            ),
          ],
        ),
      ),
    );
  }
}

// ── Shared category/subcategory icon mapping ────────────────────────────

/// Maps a category or subcategory name to a representative icon.
///
/// Categories carry an `icon_url` from the admin panel, but nothing has
/// uploaded one yet, and a grid of broken images is worse than a grid of
/// recognisable glyphs. Shared across the home grid, service search, and
/// the subcategories screen — all three draw from the same category
/// tree and previously carried their own copy of this mapping.
IconData customerServiceIcon(String? name) {
  final key = (name ?? '').toLowerCase();

  if (key.contains('ac') || key.contains('air')) {
    return Icons.ac_unit;
  }
  if (key.contains('plumb')) {
    return Icons.water_drop_outlined;
  }
  if (key.contains('electric')) {
    return Icons.bolt;
  }
  if (key.contains('carpent') || key.contains('wood')) {
    return Icons.handyman_outlined;
  }
  if (key.contains('paint')) {
    return Icons.format_paint_outlined;
  }
  if (key.contains('pest')) {
    return Icons.pest_control_outlined;
  }
  if (key.contains('clean')) {
    return Icons.cleaning_services_outlined;
  }
  if (key.contains('appliance')) {
    return Icons.kitchen_outlined;
  }

  return Icons.build_outlined;
}

// ── Saved addresses ───────────────────────────────────────────────────────

/// Icon for a saved address's label — home, office, or anything else.
IconData customerAddressIcon(String? label) {
  switch (label) {
    case 'home':
      return Icons.home_outlined;
    case 'office':
      return Icons.work_outline;
    default:
      return Icons.location_on_outlined;
  }
}

/// One saved address: type icon, label with a "Selected" badge on the
/// default, a two-line address, and an edit/delete menu.
///
/// Takes already-translated strings, like the rest of this file.
class CustomerAddressCard extends StatelessWidget {
  const CustomerAddressCard({
    super.key,
    required this.label,
    required this.title,
    required this.address,
    required this.isSelected,
    required this.selectedText,
    required this.editText,
    required this.deleteText,
    required this.onTap,
    required this.onEdit,
    required this.onDelete,
  });

  /// home | office | other — picks the icon.
  final String? label;
  final String title;
  final String address;
  final bool isSelected;
  final String selectedText;
  final String editText;
  final String deleteText;
  final VoidCallback onTap;
  final VoidCallback onEdit;
  final VoidCallback onDelete;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: ServiceTokens.card,
      borderRadius: BorderRadius.circular(16.getSize),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(16.getSize),
        child: Container(
          padding: EdgeInsets.fromLTRB(14.getSize, 14.getSize, 4.getSize, 14.getSize),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(16.getSize),
            border: Border.all(
              color: isSelected ? ServiceTokens.tileBorder : ServiceTokens.stroke,
            ),
          ),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              CustomerIconTile(icon: customerAddressIcon(label), size: 40),
              12.widthSpacer,
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Flexible(
                          child: BaseTextDMSans(
                            text: title,
                            fontSize: 14,
                            fontWeight: FontWeight.w600,
                            color: ServiceTokens.text,
                            textAlign: TextAlign.start,
                            maxLines: 1,
                          ),
                        ),
                        if (isSelected) ...[
                          8.widthSpacer,
                          // teal-700, not the decorative teal-500 default:
                          // this badge is read, not just seen.
                          CustomerBadge(text: selectedText, color: ServiceTokens.accent),
                        ],
                      ],
                    ),
                    4.heightSpacer,
                    BaseTextDMSans(
                      text: address,
                      fontSize: 12,
                      color: ServiceTokens.muted,
                      textAlign: TextAlign.start,
                      maxLines: 2,
                      lineHeight: 1.4,
                    ),
                  ],
                ),
              ),
              PopupMenuButton<String>(
                icon: Icon(Icons.more_vert, color: ServiceTokens.muted, size: 20.getSize),
                color: ServiceTokens.card,
                onSelected: (value) => value == 'edit' ? onEdit() : onDelete(),
                itemBuilder: (_) => [
                  PopupMenuItem(
                    value: 'edit',
                    child: BaseTextDMSans(text: editText, fontSize: 14, color: ServiceTokens.text),
                  ),
                  PopupMenuItem(
                    value: 'delete',
                    child: BaseTextDMSans(text: deleteText, fontSize: 14, color: ColorRes.errorColor),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Yes/no confirmation in the app's dialog style. Resolves to true only
/// when the confirm button is pressed.
Future<bool> showCustomerConfirmDialog({
  required String title,
  required String message,
  required String confirmText,
  required String cancelText,
  bool danger = false,
}) async {
  final confirmed = await Get.dialog<bool>(
    AlertDialog(
      backgroundColor: ServiceTokens.card,
      title: BaseTextDMSans(
        text: title,
        fontSize: 16,
        fontWeight: FontWeight.w600,
        color: ServiceTokens.text,
        textAlign: TextAlign.start,
      ),
      content: BaseTextDMSans(
        text: message,
        fontSize: 14,
        color: ServiceTokens.muted,
        textAlign: TextAlign.start,
        maxLines: 4,
      ),
      actions: [
        TextButton(
          onPressed: () => Get.back(result: false),
          child: BaseTextDMSans(
            text: cancelText,
            fontSize: 14,
            fontWeight: FontWeight.w600,
            color: ServiceTokens.muted,
          ),
        ),
        TextButton(
          onPressed: () => Get.back(result: true),
          child: BaseTextDMSans(
            text: confirmText,
            fontSize: 14,
            fontWeight: FontWeight.w600,
            color: danger ? ColorRes.errorColor : ServiceTokens.accent,
          ),
        ),
      ],
    ),
  );

  return confirmed ?? false;
}
