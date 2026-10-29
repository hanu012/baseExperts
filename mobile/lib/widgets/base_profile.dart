import 'package:easy_localization/easy_localization.dart';
import 'package:flutter_image_compress/flutter_image_compress.dart';
import 'package:image_picker/image_picker.dart';

import '../constants/app.export.dart';
import '../constants/constant.dart';

/// The profile photo shared by all three apps: the user's picture when
/// there is one, their initials when there is not (or when it fails to
/// load), and a camera badge when [onTap] lets them change it.
class ProfilePhoto extends StatelessWidget {
  const ProfilePhoto({
    super.key,
    required this.initials,
    this.url,
    this.size = 86,
    this.radius,
    this.onTap,
    this.busy = false,
  });

  final String initials;
  final String? url;
  final double size;
  final double? radius;
  final VoidCallback? onTap;

  /// An upload is in flight — dims the tile under a spinner.
  final bool busy;

  @override
  Widget build(BuildContext context) {
    final box = size.getSize;
    final corner = BorderRadius.circular(radius ?? box * 0.3);
    final photo = url;

    final fallback = Center(
      child: BaseTextDMSans(
        text: initials,
        fontSize: size * 0.3,
        fontWeight: FontWeight.w800,
        color: ServiceTokens.accent,
      ),
    );

    final tile = Container(
      height: box,
      width: box,
      decoration: BoxDecoration(color: Colors.white, borderRadius: corner),
      child: ClipRRect(
        borderRadius: corner,
        child: Stack(
          fit: StackFit.expand,
          children: [
            if (photo == null || photo.isEmpty)
              fallback
            else
              Image.network(
                photo,
                fit: BoxFit.cover,
                errorBuilder: (_, _, _) => fallback,
              ),
            if (busy)
              Container(
                color: Colors.black.withValues(alpha: 0.35),
                alignment: Alignment.center,
                child: SizedBox(
                  height: 22.getSize,
                  width: 22.getSize,
                  child: const CircularProgressIndicator(
                    strokeWidth: 2.4,
                    color: Colors.white,
                  ),
                ),
              ),
          ],
        ),
      ),
    );

    if (onTap == null) {
      return tile;
    }

    return GestureDetector(
      onTap: busy ? null : onTap,
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          tile,
          Positioned(
            right: -4.getSize,
            bottom: -4.getSize,
            child: Container(
              height: 30.getSize,
              width: 30.getSize,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: ServiceTokens.accentBright,
                border: Border.all(color: Colors.white, width: 2),
              ),
              child: Icon(Icons.photo_camera, size: 14.getSize, color: Colors.white),
            ),
          ),
        ],
      ),
    );
  }
}

/// Two letters for an avatar from any name, or '?' when there is none.
String profileInitials(String? name) {
  final parts = (name ?? '')
      .trim()
      .split(RegExp(r'\s+'))
      .where((part) => part.isNotEmpty)
      .toList();

  if (parts.isEmpty) {
    return '?';
  }

  if (parts.length == 1) {
    final one = parts.first;

    return (one.length >= 2 ? one.substring(0, 2) : one).toUpperCase();
  }

  return '${parts.first[0]}${parts[1][0]}'.toUpperCase();
}

enum _PhotoAction { camera, gallery, remove }

/// The whole change-photo flow: the Take / Choose / Remove sheet, the
/// picker, compression and the upload.
///
/// Returns the updated user — already stored through [Injector] — or null
/// when the user backed out or the request failed (a toast says which).
/// Callers only need to redraw with the new avatar URL.
Future<UserModel?> changeProfilePhoto({required bool hasPhoto}) async {
  final action = await Get.bottomSheet<_PhotoAction>(
    _PhotoSheet(hasPhoto: hasPhoto),
    isScrollControlled: true,
  );

  if (action == null) {
    return null;
  }

  CommonResponse? response;

  try {
    if (action == _PhotoAction.remove) {
      Utils.showCircularProgressLottie(true);
      response = await DataSource.instance.deleteAvatarAPI();
    } else {
      final picked = await ImagePicker().pickImage(
        source: action == _PhotoAction.camera ? ImageSource.camera : ImageSource.gallery,
        preferredCameraDevice: CameraDevice.front,
      );

      if (picked == null) {
        return null;
      }

      final path = await _compress(picked.path) ?? picked.path;

      Utils.showCircularProgressLottie(true);
      response = await DataSource.instance.uploadAvatarAPI(filePath: path);
    }
  } catch (e) {
    if (kDebugMode) {
      print('Profile photo error $e');
    }
  } finally {
    Utils.showCircularProgressLottie(false);
  }

  if (response == null || !response.isSuccess || response.data == null) {
    Utils.showToast(
      response?.fieldError('photo') ?? response?.message ?? tr(StringRes.somethingWentWrong),
      isError: true,
    );
    return null;
  }

  final user = UserModel.fromJson(response.data as Map<String, dynamic>);
  await Injector.setUserData(user, isFromEditProfile: true);

  Utils.showToast(tr(
    action == _PhotoAction.remove ? StringRes.profilePhotoRemoved : StringRes.profilePhotoUpdated,
  ));

  return user;
}

/// A square, modest-size JPEG: an avatar never shows above a few hundred
/// pixels, so there is no point sending a 12 MP camera original.
Future<String?> _compress(String sourcePath) async {
  try {
    final result = await FlutterImageCompress.compressAndGetFile(
      sourcePath,
      '${sourcePath}_avatar.jpg',
      quality: 85,
      minWidth: 800,
      minHeight: 800,
    );

    return result?.path;
  } catch (e) {
    if (kDebugMode) {
      print('Compress avatar error $e');
    }
    return null;
  }
}

class _PhotoSheet extends StatelessWidget {
  const _PhotoSheet({required this.hasPhoto});

  final bool hasPhoto;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: EdgeInsets.fromLTRB(16.getSize, 12.getSize, 16.getSize, 16.getSize),
      decoration: BoxDecoration(
        color: ServiceTokens.card,
        borderRadius: BorderRadius.vertical(top: Radius.circular(22.getSize)),
      ),
      child: SafeArea(
        top: false,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Center(
              child: Container(
                height: 4.getSize,
                width: 46.getSize,
                decoration: BoxDecoration(
                  color: ServiceTokens.stroke2,
                  borderRadius: BorderRadius.circular(3.getSize),
                ),
              ),
            ),
            16.heightSpacer,
            BaseTextDMSans(
              text: tr(StringRes.profilePhotoTitle),
              fontSize: 16,
              fontWeight: FontWeight.w800,
              color: ServiceTokens.text,
              textAlign: TextAlign.start,
            ),
            8.heightSpacer,
            option(Icons.photo_camera_outlined, StringRes.takePhoto, _PhotoAction.camera),
            option(Icons.photo_library_outlined, StringRes.chooseFromGallery, _PhotoAction.gallery),
            if (hasPhoto)
              option(Icons.delete_outline, StringRes.removePhoto, _PhotoAction.remove, danger: true),
          ],
        ),
      ),
    );
  }

  Widget option(IconData icon, String labelKey, _PhotoAction action, {bool danger = false}) {
    final color = danger ? ColorRes.errorColor : ServiceTokens.text;

    return InkWell(
      onTap: () => Get.back(result: action),
      borderRadius: BorderRadius.circular(12.getSize),
      child: Padding(
        padding: EdgeInsets.symmetric(vertical: 14.getSize, horizontal: 4.getSize),
        child: Row(
          children: [
            Icon(icon, size: 22.getSize, color: danger ? color : ServiceTokens.accent),
            14.widthSpacer,
            BaseTextDMSans(
              text: tr(labelKey),
              fontSize: 14,
              fontWeight: FontWeight.w600,
              color: color,
            ),
          ],
        ),
      ),
    );
  }
}
