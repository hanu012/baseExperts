/// SharedPreferences keys. Read and written only through [Injector] — screens
/// never touch SharedPreferences directly.
class PrefKeys {
  static const String accessToken = 'accessToken';
  static const String deviceId = 'deviceId';
  static const String deviceName = 'deviceName';
  static const String userData = 'userData';
  static const String isGuestUser = 'guestUser';
  static const String enableNotification = 'enableNotification';

  /// Admin Settings → "Call tracking in apps", cached from GET settings.
  static const String callTrackingEnabled = 'callTrackingEnabled';
  /// The one-time "Show a call bubble?" prompt (Android) has been answered.
  static const String callBubbleAsked = 'callBubbleAsked';
  static const String askBeforeNotification = 'askBeforeNotification';
  static const String skipTap = 'skip';
  static const String language = 'language';

  /// The email a signed-out user asked to be remembered. Only the address —
  /// this app never stores a password, and a temp-password flow makes that
  /// especially unwise.
  static const String rememberedEmail = 'rememberedEmail';

  /// The customer's chosen location (SPEC section 4.2). Persisted so the
  /// home header is correct on a cold start, and so a deliberate choice is
  /// not overwritten by the next GPS fix.
  static const String customerLocationLabel = 'customerLocationLabel';
  static const String customerLocationAddress = 'customerLocationAddress';
  static const String customerLatitude = 'customerLatitude';
  static const String customerLongitude = 'customerLongitude';

  /// In-progress vendor draft (SPEC 2.2). Kept so a salesman who loses the
  /// connection - or the app - resumes rather than retyping.
  static const String draftVendorId = 'draftVendorId';
}
