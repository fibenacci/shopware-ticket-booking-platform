import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';

/**
 * Rotating ticket QR ("rotating barcode" / TOTP). Polls the account endpoint
 * for the current code image and swaps it on every window boundary, with a
 * thin countdown. The secret never reaches the browser — the server returns
 * a ready-made image and the seconds left in the window.
 *
 * A screenshot is therefore worthless after the window passes, which is the
 * whole point (anti-sharing). Refresh is scheduled exactly on the window edge
 * (ttl from the endpoint) plus a small guard so the new code is always live.
 */
export default class FibRotatingQrPlugin extends Plugin {
    static options = {
        codeUrl: null,
        alt: 'Ticket QR code',
        // Safety margin so a tiny clock skew never shows an already-expired
        // code; also the floor between refreshes.
        guardSeconds: 2,
    };

    init() {
        if (!this.options.codeUrl) {
            return;
        }

        this._client = new HttpClient();
        this._image = this.el.querySelector('.fib-rotating-qr-image');
        this._countdown = this.el.querySelector('.fib-rotating-qr-countdown');
        this._timer = null;
        this._tick = null;
        this._destroyed = false;

        // Stop polling when the tab is hidden; resume on return (no point
        // rotating a QR nobody is looking at, and it saves requests).
        this._onVisibility = () => (document.hidden ? this._stop() : this._refresh());
        document.addEventListener('visibilitychange', this._onVisibility);

        this._refresh();
    }

    destroy() {
        this._destroyed = true;
        this._stop();
        document.removeEventListener('visibilitychange', this._onVisibility);
    }

    _refresh() {
        if (this._destroyed) {
            return;
        }

        this._client.get(this.options.codeUrl, (response, request) => {
            if (this._destroyed) {
                return;
            }

            if (request.status !== 200) {
                this._renderError();
                return;
            }

            let data;
            try {
                data = JSON.parse(response);
            } catch (e) {
                this._renderError();
                return;
            }

            if (!data.qrCodeDataUri) {
                this._renderError();
                return;
            }

            this._renderImage(data.qrCodeDataUri);

            const ttl = Math.max(1, parseInt(data.ttl, 10) || 1);
            this._startCountdown(ttl);
            this._scheduleRefresh(ttl + this.options.guardSeconds);
        });
    }

    _renderImage(dataUri) {
        const img = this._image.querySelector('img') || document.createElement('img');
        img.src = dataUri;
        img.width = 132;
        img.height = 132;
        img.alt = this.options.alt;
        if (!img.parentNode) {
            this._image.appendChild(img);
        }
    }

    _renderError() {
        // Keep the last good image if any; just stop the countdown and retry
        // shortly — a transient network blip must not blank the ticket.
        this._stopCountdown();
        this._scheduleRefresh(5);
    }

    _startCountdown(ttl) {
        this._stopCountdown();
        let remaining = ttl;
        const render = () => {
            if (this._countdown) {
                this._countdown.textContent = `${remaining}s`;
            }
            remaining = Math.max(0, remaining - 1);
        };
        render();
        this._tick = window.setInterval(render, 1000);
    }

    _stopCountdown() {
        if (this._tick !== null) {
            window.clearInterval(this._tick);
            this._tick = null;
        }
    }

    _scheduleRefresh(seconds) {
        if (this._timer !== null) {
            window.clearTimeout(this._timer);
        }
        this._timer = window.setTimeout(() => this._refresh(), seconds * 1000);
    }

    _stop() {
        if (this._timer !== null) {
            window.clearTimeout(this._timer);
            this._timer = null;
        }
        this._stopCountdown();
    }
}
