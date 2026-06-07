import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Validates and normalizes the operator-entered Shopware base URL. Requires
/// `https` and a host; strips path/trailing slash so `'$baseUrl/api/...'` is
/// always well-formed. There is NO silent default — an invalid URL returns
/// `null` and the caller refuses to proceed (the project's
/// no-silent-fallback-defaults rule applies to the app too).
String? normalizeBaseUrl(String raw) {
  final uri = Uri.tryParse(raw.trim());
  if (uri == null || uri.scheme != 'https' || uri.host.isEmpty) {
    return null;
  }

  final port = uri.hasPort ? ':${uri.port}' : '';
  return '${uri.scheme}://${uri.host}$port';
}

/// Persists ONLY the server base URL in the OS keystore. The access token
/// stays in memory (see [Session]); the refresh token may be added here later
/// behind biometric unlock as an explicit opt-in.
class ServerConfig {
  ServerConfig([FlutterSecureStorage? storage])
      : _storage = storage ?? const FlutterSecureStorage();

  static const String _key = 'server_base_url';
  final FlutterSecureStorage _storage;

  Future<String?> read() => _storage.read(key: _key);

  Future<void> save(String url) async {
    final normalized = normalizeBaseUrl(url);
    if (normalized == null) {
      throw ArgumentError('Server URL must be a valid https:// address.');
    }
    await _storage.write(key: _key, value: normalized);
  }

  Future<void> clear() => _storage.delete(key: _key);
}
