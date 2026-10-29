/// The signed-in user.
///
/// Not in the original port list, but the reference controller calls
/// `UserModel.fromJson(commonResponse.data)` and reads
/// `userModel.authentication?.accessToken`, so the foundation needs it.
///
/// [fromJson] takes the `data` object the auth endpoints return —
/// `{ user: {...}, token: "..." }` — and flattens it, keeping the demo-app's
/// nested `authentication.accessToken` accessor so ported screens are
/// unchanged while still matching this backend's actual shape.
class UserModel {
  int? id;
  String? name;
  String? email;

  /// Phone from the role's own table (customers / vendors / salesmen).
  String? phone;

  /// Profile photo, or null for none.
  String? avatarUrl;
  String? role;
  String? emailVerifiedAt;
  String? createdAt;
  bool mustChangePassword = false;

  /// 'pending' | 'approved' | 'rejected'. Admin approval is the only gate on
  /// an account, so this decides whether the app opens home or the
  /// account-verification screen.
  String? approvalStatus;

  /// The admin's reason, set only on a rejection. Shown to the user so a
  /// rejection is not a dead end they have to phone up about.
  String? approvalNote;

  Authentication? authentication;

  UserModel({
    this.id,
    this.name,
    this.email,
    this.role,
    this.emailVerifiedAt,
    this.createdAt,
    this.approvalStatus,
    this.approvalNote,
    this.authentication,
  });

  UserModel.fromJson(Map<String, dynamic> json) {
    // Accepts either the wrapped `{user: {...}, token: ...}` payload from the
    // auth endpoints or a bare user object from /api/user.
    final user = json['user'] is Map<String, dynamic>
        ? json['user'] as Map<String, dynamic>
        : json;

    id = user['id'] as int?;
    name = user['name'] as String?;
    email = user['email'] as String?;
    phone = user['phone'] as String?;
    avatarUrl = user['avatar_url'] as String?;
    role = user['role'] as String?;
    emailVerifiedAt = user['email_verified_at'] as String?;
    createdAt = user['created_at'] as String?;
    mustChangePassword = (user['must_change_password'] as bool?) ?? false;
    // Defaults to pending rather than approved when the key is missing: an
    // unknown state must fail closed, or a malformed response would walk
    // someone straight into a home screen the API will refuse to serve.
    approvalStatus = user['approval_status'] as String? ?? 'pending';
    approvalNote = user['approval_note'] as String?;

    final token = json['token'];
    if (token is String && token.isNotEmpty) {
      authentication = Authentication(accessToken: token);
    }
  }

  bool get isEmailVerified => emailVerifiedAt != null;

  /// The account can actually use the app. Everything else — pending,
  /// rejected, or an unrecognised value from a newer server — routes to the
  /// verification screen.
  bool get isApproved => approvalStatus == 'approved';

  bool get isRejected => approvalStatus == 'rejected';

  Map<String, dynamic> toJson() => {
        'user': {
          'id': id,
          'name': name,
          'email': email,
          'phone': phone,
          'avatar_url': avatarUrl,
          'role': role,
          'email_verified_at': emailVerifiedAt,
          'created_at': createdAt,
          'must_change_password': mustChangePassword,
          'approval_status': approvalStatus,
          'approval_note': approvalNote,
        },
        'token': authentication?.accessToken,
      };
}

class Authentication {
  String? accessToken;

  Authentication({this.accessToken});

  Authentication.fromJson(Map<String, dynamic> json) {
    accessToken = json['token'] as String? ?? json['accessToken'] as String?;
  }

  Map<String, dynamic> toJson() => {'accessToken': accessToken};
}
