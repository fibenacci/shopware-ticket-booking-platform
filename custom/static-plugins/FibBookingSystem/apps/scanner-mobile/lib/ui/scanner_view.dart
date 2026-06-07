import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../api/scan_api.dart';
import '../api/token.dart';

/// The gate scanner: continuous camera, decode → forward → verdict overlay.
/// Static and rotating QR are both just strings — the server decides which is
/// which, so this view needs no rotating-specific handling.
class ScannerView extends StatefulWidget {
  const ScannerView({super.key, required this.api, required this.onLogout});

  final ScanApi api;
  final VoidCallback onLogout;

  @override
  State<ScannerView> createState() => _ScannerViewState();
}

class _ScannerViewState extends State<ScannerView> {
  final MobileScannerController _controller = MobileScannerController(
    formats: const [BarcodeFormat.qrCode],
    detectionSpeed: DetectionSpeed.normal,
  );

  bool _checkOutEnabled = false;
  String _direction = directionCheckIn;
  bool _torchOn = false;
  bool _busy = false;

  String? _lastToken;
  int _lastScanMs = 0;
  _Verdict? _verdict;

  @override
  void initState() {
    super.initState();
    _loadConfig();
  }

  Future<void> _loadConfig() async {
    try {
      final config = await widget.api.fetchScannerConfig();
      if (mounted) {
        setState(() => _checkOutEnabled = config['checkOutEnabled'] == true);
      }
    } catch (_) {
      // Safe default (check-out off) already applies.
    }
  }

  Future<void> _onDetect(BarcodeCapture capture) async {
    if (_busy) {
      return;
    }

    final raw = capture.barcodes.isEmpty ? null : capture.barcodes.first.rawValue;
    final token = extractScanToken(raw);
    if (token == null) {
      return;
    }

    // The camera fires continuously — ignore the same token within 3s.
    final nowMs = DateTime.now().millisecondsSinceEpoch;
    if (token == _lastToken && nowMs - _lastScanMs < 3000) {
      return;
    }
    _lastToken = token;
    _lastScanMs = nowMs;

    setState(() => _busy = true);
    try {
      final response = await widget.api.scanTicket(token, direction: _direction);
      await HapticFeedback.mediumImpact();
      _show(_Verdict.fromResponse(response));
    } on ScanException catch (e) {
      if (e.requiresLogin) {
        widget.onLogout();
        return;
      }
      _show(_Verdict(ok: false, title: 'Rejected', detail: e.message));
    } catch (e) {
      _show(_Verdict(ok: false, title: 'Error', detail: e.toString()));
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  void _show(_Verdict verdict) {
    if (mounted) {
      setState(() => _verdict = verdict);
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Scan tickets'),
        actions: [
          IconButton(
            tooltip: 'Torch',
            icon: Icon(_torchOn ? Icons.flash_on : Icons.flash_off),
            onPressed: () {
              _controller.toggleTorch();
              setState(() => _torchOn = !_torchOn);
            },
          ),
          IconButton(
            tooltip: 'Sign out',
            icon: const Icon(Icons.logout),
            onPressed: widget.onLogout,
          ),
        ],
      ),
      body: Column(
        children: [
          if (_checkOutEnabled) _directionToggle(),
          Expanded(
            child: Stack(
              fit: StackFit.expand,
              alignment: Alignment.center,
              children: [
                MobileScanner(controller: _controller, onDetect: _onDetect),
                IgnorePointer(
                  child: Container(
                    margin: const EdgeInsets.all(48),
                    decoration: BoxDecoration(
                      border: Border.all(color: Colors.white70, width: 3),
                      borderRadius: BorderRadius.circular(16),
                    ),
                  ),
                ),
                if (_busy) const CircularProgressIndicator(),
              ],
            ),
          ),
          if (_verdict != null) _verdictPanel(_verdict!),
        ],
      ),
    );
  }

  Widget _directionToggle() {
    return Padding(
      padding: const EdgeInsets.all(8),
      child: SegmentedButton<String>(
        segments: const [
          ButtonSegment(value: directionCheckIn, label: Text('Check in'), icon: Icon(Icons.login)),
          ButtonSegment(value: directionCheckOut, label: Text('Check out'), icon: Icon(Icons.logout)),
        ],
        selected: {_direction},
        onSelectionChanged: (selection) => setState(() => _direction = selection.first),
      ),
    );
  }

  Widget _verdictPanel(_Verdict verdict) {
    final color = verdict.ok ? Colors.green.shade700 : Theme.of(context).colorScheme.error;
    return Container(
      width: double.infinity,
      color: color,
      padding: const EdgeInsets.all(20),
      child: Row(
        children: [
          Icon(verdict.ok ? Icons.check_circle : Icons.cancel, color: Colors.white, size: 36),
          const SizedBox(width: 16),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  verdict.title,
                  style: const TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.bold),
                ),
                if (verdict.detail != null)
                  Text(verdict.detail!, style: const TextStyle(color: Colors.white)),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// View model derived from the scan endpoint's `{verdict, valid, ticket}`
/// response (see `BookingTicketScanController`).
class _Verdict {
  _Verdict({required this.ok, required this.title, this.detail});

  final bool ok;
  final String title;
  final String? detail;

  factory _Verdict.fromResponse(Map<String, dynamic> response) {
    final ok = response['valid'] == true;
    final verdict = response['verdict'] as String? ?? 'unknown';
    final ticket = response['ticket'] as Map<String, dynamic>?;

    final parts = <String>[];
    if (ticket != null) {
      if (ticket['ticketNumber'] != null) parts.add(ticket['ticketNumber'] as String);
      if (ticket['seatLabel'] != null) parts.add('Seat ${ticket['seatLabel']}');
    }

    return _Verdict(
      ok: ok,
      title: _label(verdict),
      detail: parts.isEmpty ? null : parts.join(' · '),
    );
  }

  static String _label(String verdict) {
    switch (verdict) {
      case 'valid':
        return 'Admitted';
      case 'checked_out':
        return 'Checked out';
      case 'already_scanned':
        return 'Already scanned';
      case 'not_checked_in':
        return 'Not checked in';
      case 'not_yet_valid':
        return 'Not yet valid';
      case 'entry_limit_reached':
        return 'Entry limit reached';
      case 'expired':
        return 'Expired';
      case 'revoked':
        return 'Revoked';
      case 'not_found':
        return 'Not found';
      case 'invalid_code':
        return 'Invalid code';
      default:
        return verdict;
    }
  }
}
