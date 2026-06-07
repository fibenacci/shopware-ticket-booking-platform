import 'package:flutter/material.dart';

import '../config/server_config.dart';

/// First-run server setup: the native app is not same-origin, so it must be
/// told which Shopware instance to talk to. Validated to https with a host —
/// no silent default.
class SettingsView extends StatefulWidget {
  const SettingsView({super.key, this.initialUrl, required this.onSaved});

  final String? initialUrl;
  final Future<void> Function(String url) onSaved;

  @override
  State<SettingsView> createState() => _SettingsViewState();
}

class _SettingsViewState extends State<SettingsView> {
  late final TextEditingController _controller =
      TextEditingController(text: widget.initialUrl ?? 'https://');
  String? _error;
  bool _saving = false;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final value = _controller.text.trim();
    if (normalizeBaseUrl(value) == null) {
      setState(() => _error = 'Enter a valid https:// address.');
      return;
    }

    setState(() {
      _saving = true;
      _error = null;
    });

    try {
      await widget.onSaved(value);
    } catch (e) {
      if (mounted) {
        setState(() {
          _error = e is ArgumentError ? e.message.toString() : e.toString();
          _saving = false;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Server')),
      body: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Text('Shopware instance the scanner connects to.'),
            const SizedBox(height: 16),
            TextField(
              controller: _controller,
              keyboardType: TextInputType.url,
              autocorrect: false,
              enabled: !_saving,
              decoration: InputDecoration(
                labelText: 'Server URL',
                hintText: 'https://shop.example.com',
                border: const OutlineInputBorder(),
                errorText: _error,
              ),
            ),
            const SizedBox(height: 24),
            FilledButton(
              onPressed: _saving ? null : _save,
              child: _saving
                  ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2))
                  : const Text('Continue'),
            ),
          ],
        ),
      ),
    );
  }
}
