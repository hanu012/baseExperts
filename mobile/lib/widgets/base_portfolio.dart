import 'package:easy_localization/easy_localization.dart';
import 'package:flutter/cupertino.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:video_player/video_player.dart';
import 'package:video_thumbnail/video_thumbnail.dart';

import '../constants/constant.dart';
import '../constants/string_res.dart';
import 'base_services.dart';
import 'base_text.dart';

/// Portfolio media pieces shared by the vendor app (their own uploads) and
/// the customer app (a vendor's work on the detail page): a poster frame
/// for a video tile, a video player, and a full-screen swipeable viewer.
///
/// Shared rather than copied per app because they show the same files the
/// same way — a fix to video playback should not have to be made twice.

// ── Viewer ────────────────────────────────────────────────────────────────

/// One photo or video in [showPortfolioViewer].
class PortfolioViewerItem {
  const PortfolioViewerItem({required this.type, required this.url, this.caption});

  /// 'image' | 'video'
  final String type;
  final String url;

  /// Shown over the media — the service it was filed under, e.g.
  /// "AC Repair › Gas Filling".
  final String? caption;

  bool get isVideo => type == 'video';
}

/// Full-screen viewer: swipe between items, pinch to zoom photos, videos
/// play in place.
///
/// A full-screen dialog rather than a pushed route, so closing it returns
/// to the exact scroll position of the grid it was opened from.
void showPortfolioViewer(List<PortfolioViewerItem> items, {int initialIndex = 0}) {
  if (items.isEmpty) {
    return;
  }

  Get.dialog(
    Dialog.fullscreen(
      backgroundColor: Colors.black,
      child: PortfolioViewer(items: items, initialIndex: initialIndex),
    ),
  );
}

class PortfolioViewer extends StatefulWidget {
  const PortfolioViewer({super.key, required this.items, this.initialIndex = 0});

  final List<PortfolioViewerItem> items;
  final int initialIndex;

  @override
  State<PortfolioViewer> createState() => _PortfolioViewerState();
}

class _PortfolioViewerState extends State<PortfolioViewer> {
  late final PageController _pages;
  late int _index;

  @override
  void initState() {
    super.initState();
    _index = widget.initialIndex.clamp(0, widget.items.length - 1);
    _pages = PageController(initialPage: _index);
  }

  @override
  void dispose() {
    _pages.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final current = widget.items[_index];

    return Stack(
      children: [
        Positioned.fill(
          child: PageView.builder(
            controller: _pages,
            itemCount: widget.items.length,
            onPageChanged: (index) => setState(() => _index = index),
            itemBuilder: (_, index) {
              final item = widget.items[index];

              if (item.isVideo) {
                // Only the page on screen gets a player: PageView builds
                // its neighbours too, and a neighbour's autoplay would be
                // sound from a video nobody is looking at.
                return index == _index
                    ? PortfolioVideoPlayer(key: ValueKey(item.url), url: item.url)
                    : const Center(
                        child: Icon(Icons.play_circle_outline, color: Colors.white38, size: 56),
                      );
              }

              return InteractiveViewer(
                maxScale: 4,
                child: Image.network(
                  item.url,
                  fit: BoxFit.contain,
                  loadingBuilder: (_, child, progress) => progress == null
                      ? child
                      : const Center(child: CupertinoActivityIndicator(color: Colors.white)),
                  errorBuilder: (_, _, _) => const Center(child: _MediaError(video: false)),
                ),
              );
            },
          ),
        ),
        // Top bar on a scrim so it stays legible over a bright photo.
        Positioned(
          top: 0,
          left: 0,
          right: 0,
          child: Container(
            decoration: const BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
                colors: [Colors.black54, Colors.transparent],
              ),
            ),
            child: SafeArea(
              bottom: false,
              child: Padding(
                padding: EdgeInsets.fromLTRB(12.getSize, 8.getSize, 12.getSize, 16.getSize),
                child: Row(
                  children: [
                    _ViewerButton(icon: Icons.close, onTap: Get.back),
                    12.widthSpacer,
                    Expanded(
                      child: current.caption == null
                          ? const SizedBox.shrink()
                          : BaseTextDMSans(
                              text: current.caption!,
                              fontSize: 14,
                              fontWeight: FontWeight.w600,
                              color: Colors.white,
                              textAlign: TextAlign.start,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                            ),
                    ),
                    if (widget.items.length > 1)
                      BaseTextDMSans(
                        text: '${_index + 1} / ${widget.items.length}',
                        fontSize: 12,
                        fontWeight: FontWeight.w600,
                        color: Colors.white70,
                      ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ],
    );
  }
}

class _ViewerButton extends StatelessWidget {
  const _ViewerButton({required this.icon, required this.onTap});

  final IconData icon;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Colors.white.withValues(alpha: 0.15),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(13.getSize)),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(13.getSize),
        child: SizedBox(
          height: 42.getSize,
          width: 42.getSize,
          child: Icon(icon, size: 19.getSize, color: Colors.white),
        ),
      ),
    );
  }
}

class _MediaError extends StatelessWidget {
  const _MediaError({required this.video});

  final bool video;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.symmetric(horizontal: 24.getSize),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(
            video ? Icons.videocam_off_outlined : Icons.broken_image_outlined,
            size: 44.getSize,
            color: ServiceTokens.muted2,
          ),
          12.heightSpacer,
          BaseTextDMSans(
            text: tr(video ? StringRes.videoLoadFailed : StringRes.imageLoadFailed),
            fontSize: 14,
            color: ServiceTokens.muted2,
            textAlign: TextAlign.center,
            maxLines: 2,
          ),
        ],
      ),
    );
  }
}

// ── Grid tile ─────────────────────────────────────────────────────────────

/// One square tile in a portfolio grid: the photo, or a video's poster
/// frame with a play mark. [badge] floats in the bottom-left corner.
class PortfolioMediaTile extends StatelessWidget {
  const PortfolioMediaTile({
    super.key,
    required this.type,
    required this.url,
    this.onTap,
    this.badge,
    this.radius = 12,
  });

  final String? type;
  final String? url;
  final VoidCallback? onTap;
  final Widget? badge;
  final double radius;

  @override
  Widget build(BuildContext context) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(radius.getSize),
      child: Material(
        color: ServiceTokens.card2,
        child: InkWell(
          onTap: url == null ? null : onTap,
          child: Stack(
            fit: StackFit.expand,
            children: [
              if (type == 'video' && url != null)
                PortfolioVideoThumbnail(url: url!)
              else if (type == 'video')
                Center(
                  child: Icon(Icons.play_circle_outline, color: ServiceTokens.muted, size: 36.getSize),
                )
              else if (url != null)
                Image.network(
                  url!,
                  fit: BoxFit.cover,
                  // A tile is small, so a spinner would be more noise than
                  // information — it stays the card colour until it lands.
                  errorBuilder: (_, _, _) => Center(
                    child: Icon(Icons.broken_image_outlined, size: 22.getSize, color: ServiceTokens.muted2),
                  ),
                ),
              if (badge != null)
                Positioned(left: 6.getSize, bottom: 6.getSize, right: 6.getSize, child: badge!),
            ],
          ),
        ),
      ),
    );
  }
}

// ── Video ─────────────────────────────────────────────────────────────────

/// Plays one portfolio video.
///
/// Stateful because a player owns a platform resource that must be
/// disposed. Deliberately minimal controls: tap to play/pause, a scrub bar
/// and an elapsed/total readout — these are clips of work, capped at 60
/// seconds by the vendor app's picker.
class PortfolioVideoPlayer extends StatefulWidget {
  const PortfolioVideoPlayer({super.key, required this.url});

  final String url;

  @override
  State<PortfolioVideoPlayer> createState() => _PortfolioVideoPlayerState();
}

class _PortfolioVideoPlayerState extends State<PortfolioVideoPlayer> {
  VideoPlayerController? _controller;
  bool _failed = false;

  @override
  void initState() {
    super.initState();
    _open();
  }

  Future<void> _open() async {
    final controller = VideoPlayerController.networkUrl(Uri.parse(widget.url));

    try {
      await controller.initialize();
    } catch (e) {
      if (kDebugMode) {
        print('Video init error $e');
      }
      await controller.dispose();

      if (mounted) {
        setState(() => _failed = true);
      }
      return;
    }

    // Opened by tapping the clip, so the viewer already said they want to
    // watch it — a second tap would just be a step in the way.
    await controller.setLooping(true);
    await controller.play();

    if (!mounted) {
      await controller.dispose();
      return;
    }

    setState(() => _controller = controller);
  }

  @override
  void dispose() {
    _controller?.dispose();
    super.dispose();
  }

  void _togglePlay() {
    final controller = _controller;
    if (controller == null) {
      return;
    }

    setState(() {
      controller.value.isPlaying ? controller.pause() : controller.play();
    });
  }

  @override
  Widget build(BuildContext context) {
    if (_failed) {
      return const Center(child: _MediaError(video: true));
    }

    final controller = _controller;

    if (controller == null) {
      return const Center(child: CupertinoActivityIndicator(color: Colors.white));
    }

    return Stack(
      children: [
        Positioned.fill(
          child: GestureDetector(
            onTap: _togglePlay,
            child: Center(
              child: AspectRatio(
                aspectRatio: controller.value.aspectRatio,
                child: VideoPlayer(controller),
              ),
            ),
          ),
        ),
        // Only while paused: an overlay on a playing video is just in the way.
        if (!controller.value.isPlaying)
          Positioned.fill(
            child: IgnorePointer(
              child: Center(
                child: Container(
                  padding: EdgeInsets.all(14.getSize),
                  decoration: BoxDecoration(
                    color: Colors.black.withValues(alpha: 0.46),
                    shape: BoxShape.circle,
                  ),
                  child: Icon(Icons.play_arrow_rounded, size: 44.getSize, color: Colors.white),
                ),
              ),
            ),
          ),
        Positioned(
          left: 0,
          right: 0,
          bottom: 0,
          child: SafeArea(
            top: false,
            child: Padding(
              padding: EdgeInsets.fromLTRB(16.getSize, 0, 16.getSize, 16.getSize),
              child: _controls(controller),
            ),
          ),
        ),
      ],
    );
  }

  Widget _controls(VideoPlayerController controller) {
    return ValueListenableBuilder<VideoPlayerValue>(
      valueListenable: controller,
      builder: (_, value, _) {
        return Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            VideoProgressIndicator(
              controller,
              allowScrubbing: true,
              padding: EdgeInsets.symmetric(vertical: 10.getSize),
              colors: const VideoProgressColors(
                playedColor: ServiceTokens.accentBright,
                bufferedColor: Colors.white24,
                backgroundColor: Colors.white12,
              ),
            ),
            Row(
              children: [
                BaseTextDMSans(
                  text: _format(value.position),
                  fontSize: 12,
                  fontWeight: FontWeight.w600,
                  color: Colors.white,
                ),
                const Spacer(),
                BaseTextDMSans(
                  text: _format(value.duration),
                  fontSize: 12,
                  fontWeight: FontWeight.w600,
                  color: Colors.white70,
                ),
              ],
            ),
          ],
        );
      },
    );
  }

  /// m:ss — clips are capped at 60 seconds, so hours are always zero.
  String _format(Duration duration) {
    final minutes = duration.inMinutes;
    final seconds = duration.inSeconds.remainder(60).toString().padLeft(2, '0');

    return '$minutes:$seconds';
  }
}

/// Poster frame for a video tile.
///
/// Decoded on the device: nothing generates a poster server-side, so the
/// alternative is every video tile looking the same.
///
/// Memoised per URL in [_cache]: grids rebuild often (an upload's progress
/// ticks several times a second), and re-decoding a frame out of a
/// multi-megabyte clip each time would make scrolling stutter.
class PortfolioVideoThumbnail extends StatefulWidget {
  const PortfolioVideoThumbnail({super.key, required this.url});

  final String url;

  @override
  State<PortfolioVideoThumbnail> createState() => _PortfolioVideoThumbnailState();
}

class _PortfolioVideoThumbnailState extends State<PortfolioVideoThumbnail> {
  static final Map<String, Uint8List?> _cache = {};

  Uint8List? _bytes;
  bool _done = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    if (_cache.containsKey(widget.url)) {
      _bytes = _cache[widget.url];
      _done = true;
      return;
    }

    Uint8List? bytes;

    try {
      bytes = await VideoThumbnail.thumbnailData(
        video: widget.url,
        imageFormat: ImageFormat.JPEG,
        maxWidth: 400,
        quality: 60,
      );
    } catch (e) {
      if (kDebugMode) {
        print('Video thumbnail error $e');
      }
    }

    // Only successes are cached: a failure is usually the network, and
    // caching it would pin the tile blank for the rest of the session.
    if (bytes != null) {
      _cache[widget.url] = bytes;
    }

    if (!mounted) {
      return;
    }

    setState(() {
      _bytes = bytes;
      _done = true;
    });
  }

  @override
  Widget build(BuildContext context) {
    final bytes = _bytes;

    return Stack(
      fit: StackFit.expand,
      children: [
        if (bytes != null)
          Image.memory(bytes, fit: BoxFit.cover)
        else if (!_done)
          const Center(child: CupertinoActivityIndicator(color: ServiceTokens.muted)),
        // Always drawn: it is what marks a tile as a video.
        Center(
          child: Container(
            padding: EdgeInsets.all(5.getSize),
            decoration: BoxDecoration(
              color: Colors.black.withValues(alpha: bytes == null ? 0.0 : 0.42),
              shape: BoxShape.circle,
            ),
            child: Icon(
              Icons.play_arrow_rounded,
              color: bytes == null ? ServiceTokens.muted : Colors.white,
              size: 26.getSize,
            ),
          ),
        ),
      ],
    );
  }
}

// ── Empty state frame ─────────────────────────────────────────────────────

/// A dashed-outline container — reads as a slot waiting to be filled
/// rather than content that failed to load. Painted rather than pulled
/// from a package: one empty state does not justify a dependency.
class DottedBorderBox extends StatelessWidget {
  const DottedBorderBox({super.key, required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    return CustomPaint(
      painter: _DashedRectPainter(color: ServiceTokens.stroke2, radius: 18.getSize),
      child: Container(
        width: double.infinity,
        padding: EdgeInsets.symmetric(horizontal: 24.getSize, vertical: 38.getSize),
        child: child,
      ),
    );
  }
}

class _DashedRectPainter extends CustomPainter {
  _DashedRectPainter({required this.color, required this.radius});

  final Color color;
  final double radius;

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = color
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.4;

    final path = Path()..addRRect(RRect.fromRectAndRadius(Offset.zero & size, Radius.circular(radius)));

    // Walk the rounded-rect outline and draw every other segment.
    for (final metric in path.computeMetrics()) {
      var distance = 0.0;

      while (distance < metric.length) {
        canvas.drawPath(metric.extractPath(distance, distance + 6), paint);
        distance += 11;
      }
    }
  }

  @override
  bool shouldRepaint(_DashedRectPainter oldDelegate) =>
      oldDelegate.color != color || oldDelegate.radius != radius;
}
