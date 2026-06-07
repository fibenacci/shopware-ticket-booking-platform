/// In-memory session — mirrors the web scanner's strict posture: tokens live
/// ONLY in memory (never disk), so a stolen device holds no session after the
/// app is killed and XSS-style persisted-credential theft has no surface.
/// Access expiry is tracked with a 30s safety margin. Pure and unit-testable
/// with an injected clock (milliseconds since epoch).
class Session {
  String? accessToken;
  String? refreshToken;
  int expiresAtMs = 0;

  bool isAuthenticated(int nowMs) => accessToken != null && nowMs < expiresAtMs;

  void logout() {
    accessToken = null;
    refreshToken = null;
    expiresAtMs = 0;
  }

  /// Stores tokens from an OAuth response. Keeps the existing refresh token
  /// when the response omits one (refresh_token grant responses often do).
  void store(Map<String, dynamic> payload, int nowMs) {
    accessToken = payload['access_token'] as String?;
    refreshToken = (payload['refresh_token'] as String?) ?? refreshToken;

    final expiresIn = (payload['expires_in'] as num?)?.toInt() ?? 0;
    final marginSeconds = expiresIn - 30;
    expiresAtMs = nowMs + (marginSeconds > 0 ? marginSeconds : 0) * 1000;
  }
}
