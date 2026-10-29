/// Envelope every API method returns, per CLAUDE.md's Flutter conventions.
///
/// It maps the backend's `{ success, data, error: { code, message } }` shape
/// onto the accessors the demo-app screens already use — `.status`, `.data`,
/// `.message` — so ported screens compile unchanged:
///
///   status      <- success        (true/false)
///   data        <- data
///   message     <- error.message, falling back to data.message
///   errorCode   <- error.code     (EMAIL_NOT_VERIFIED, VALIDATION_FAILED, ...)
///   fieldErrors <- error.fields   (per-field validation messages)
class CommonResponse<T> {
  bool? status;
  int? statusCode;
  String? message;
  T? data;
  String? errorCode;
  Map<String, List<String>>? fieldErrors;

  /// Pagination metadata on list endpoints (task 5.3's
  /// `ApiResponse::paginated()`-shaped responses) — a sibling of `data`,
  /// not nested inside it, so it's parsed separately here.
  Map<String, dynamic>? meta;

  CommonResponse({
    this.status,
    this.statusCode,
    this.message,
    this.data,
    this.errorCode,
    this.fieldErrors,
    this.meta,
  });

  CommonResponse.fromJson(Map<String, dynamic> json, {this.statusCode}) {
    status = json['success'] as bool?;
    data = json['data'] as T?;
    meta = json['meta'] as Map<String, dynamic>?;

    final error = json['error'];
    if (error is Map<String, dynamic>) {
      errorCode = error['code'] as String?;
      message = error['message'] as String?;

      final fields = error['fields'];
      if (fields is Map<String, dynamic>) {
        fieldErrors = fields.map(
          (key, value) => MapEntry(
            key,
            (value as List<dynamic>).map((e) => e.toString()).toList(),
          ),
        );
      }
    }

    // Successful responses carry their human-readable text inside data.
    if (message == null && data is Map<String, dynamic>) {
      message = (data as Map<String, dynamic>)['message'] as String?;
    }
  }

  /// True when the request succeeded and carried a payload.
  bool get isSuccess => status == true;

  /// First validation message for [field], if the server rejected it.
  String? fieldError(String field) => fieldErrors?[field]?.first;

  Map<String, dynamic> toJson() => {
        'success': status,
        'data': data,
        if (errorCode != null) 'error': {'code': errorCode, 'message': message},
      };
}
