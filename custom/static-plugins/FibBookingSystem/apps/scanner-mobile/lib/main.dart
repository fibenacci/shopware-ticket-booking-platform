import 'package:flutter/material.dart';

import 'api/scan_api.dart';
import 'config/server_config.dart';
import 'ui/login_view.dart';
import 'ui/scanner_view.dart';
import 'ui/settings_view.dart';

void main() => runApp(const BookingScannerApp());

class BookingScannerApp extends StatelessWidget {
  const BookingScannerApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Booking Scanner',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        useMaterial3: true,
        colorScheme: ColorScheme.fromSeed(
          seedColor: const Color(0xFF6366F1),
          brightness: Brightness.dark,
        ),
      ),
      home: const _Root(),
    );
  }
}

/// Decides the screen from app state: no server URL → setup; not logged in →
/// login; otherwise → scanner. State is deliberately tiny (one widget), so the
/// app needs no state-management dependency.
class _Root extends StatefulWidget {
  const _Root();

  @override
  State<_Root> createState() => _RootState();
}

class _RootState extends State<_Root> {
  final ServerConfig _serverConfig = ServerConfig();

  String? _baseUrl;
  ScanApi? _api;
  bool _loading = true;
  bool _loggedIn = false;

  @override
  void initState() {
    super.initState();
    _bootstrap();
  }

  Future<void> _bootstrap() async {
    final url = await _serverConfig.read();
    setState(() {
      _baseUrl = url;
      _api = url == null ? null : ScanApi(baseUrl: url);
      _loggedIn = false;
      _loading = false;
    });
  }

  Future<void> _onServerSaved(String url) async {
    await _serverConfig.save(url);
    await _bootstrap();
  }

  Future<void> _onChangeServer() async {
    _api?.logout();
    await _serverConfig.clear();
    await _bootstrap();
  }

  void _onLoggedIn() => setState(() => _loggedIn = true);

  void _onLogout() {
    _api?.logout();
    setState(() => _loggedIn = false);
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }
    if (_api == null) {
      return SettingsView(initialUrl: _baseUrl, onSaved: _onServerSaved);
    }
    if (!_loggedIn) {
      return LoginView(
        api: _api!,
        baseUrl: _baseUrl!,
        onLoggedIn: _onLoggedIn,
        onChangeServer: _onChangeServer,
      );
    }
    return ScannerView(api: _api!, onLogout: _onLogout);
  }
}
