import 'package:flutter/material.dart';

import '../api/scan_api.dart';

/// Operator login against the Admin-API password grant. Tokens land in memory
/// only (see [ScanApi]/[Session]); nothing is written to disk.
class LoginView extends StatefulWidget {
  const LoginView({
    super.key,
    required this.api,
    required this.baseUrl,
    required this.onLoggedIn,
    required this.onChangeServer,
  });

  final ScanApi api;
  final String baseUrl;
  final VoidCallback onLoggedIn;
  final VoidCallback onChangeServer;

  @override
  State<LoginView> createState() => _LoginViewState();
}

class _LoginViewState extends State<LoginView> {
  final TextEditingController _username = TextEditingController();
  final TextEditingController _password = TextEditingController();
  String? _error;
  bool _busy = false;

  @override
  void dispose() {
    _username.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _login() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      await widget.api.login(_username.text.trim(), _password.text);
      widget.onLoggedIn();
    } on ScanException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } catch (e) {
      if (mounted) setState(() => _error = e.toString());
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Sign in'),
        actions: [
          IconButton(
            tooltip: 'Change server',
            icon: const Icon(Icons.dns_outlined),
            onPressed: widget.onChangeServer,
          ),
        ],
      ),
      body: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(widget.baseUrl, style: Theme.of(context).textTheme.bodySmall),
            const SizedBox(height: 16),
            TextField(
              controller: _username,
              autocorrect: false,
              enabled: !_busy,
              textInputAction: TextInputAction.next,
              decoration: const InputDecoration(labelText: 'Username', border: OutlineInputBorder()),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _password,
              obscureText: true,
              enabled: !_busy,
              onSubmitted: (_) => _busy ? null : _login(),
              decoration: const InputDecoration(labelText: 'Password', border: OutlineInputBorder()),
            ),
            if (_error != null) ...[
              const SizedBox(height: 12),
              Text(_error!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
            ],
            const SizedBox(height: 24),
            FilledButton(
              onPressed: _busy ? null : _login,
              child: _busy
                  ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2))
                  : const Text('Sign in'),
            ),
          ],
        ),
      ),
    );
  }
}
