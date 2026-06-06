import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';

export default class FibBookingWidgetPlugin extends Plugin {
    static options = {
        productId: null,
        resourceId: null,
        slotMinutes: 60,
        holdRoute: null,
        cartAddRoute: null,
    };

    init() {
        if (!this.options.productId || !this.options.resourceId || !this.options.holdRoute || !this.options.cartAddRoute) {
            return;
        }

        this._client = new HttpClient();
        this._errorElement = this.el.querySelector('.fib-booking-widget-error');
        this._submitButton = this.el.querySelector('button[type="submit"]');
        this._registerEvents();
    }

    _registerEvents() {
        this.el.addEventListener('submit', this._onSubmit.bind(this));
    }

    _onSubmit(event) {
        event.preventDefault();

        const startsAt = this._getStartsAt();
        const quantity = this._getQuantity();

        if (!startsAt || !quantity) {
            this._showError('Booking data is incomplete.');
            return;
        }

        const endsAt = new Date(startsAt.getTime() + Number.parseInt(this.options.slotMinutes, 10) * 60000);

        this._setLoading(true);
        this._hideError();

        this._createHold(startsAt, endsAt, quantity)
            .then((hold) => this._addToCart(hold, quantity))
            .then(() => window.location.reload())
            .catch((error) => this._showError(error.message || 'Booking failed.'))
            .finally(() => this._setLoading(false));
    }

    _createHold(startsAt, endsAt, quantity) {
        return this._postJson(this.options.holdRoute, {
            resourceId: this.options.resourceId,
            startsAt: startsAt.toISOString(),
            endsAt: endsAt.toISOString(),
            quantity,
        });
    }

    _addToCart(hold, quantity) {
        return this._postJson(this.options.cartAddRoute, {
            productId: this.options.productId,
            holdId: hold.id,
            holdToken: hold.token,
            quantity,
        });
    }

    _postJson(route, payload) {
        return new Promise((resolve, reject) => {
            this._client.post(
                route,
                JSON.stringify(payload),
                (response, request) => {
                    let decoded;

                    try {
                        decoded = JSON.parse(response);
                    } catch (error) {
                        reject(new Error('Invalid server response.'));
                        return;
                    }

                    if (request && request.status >= 400) {
                        reject(new Error(decoded.error || 'Booking request failed.'));
                        return;
                    }

                    if (decoded.success === false || decoded.error) {
                        reject(new Error(decoded.error || 'Booking request failed.'));
                        return;
                    }

                    resolve(decoded);
                },
                'application/json',
            );
        });
    }

    _getStartsAt() {
        const field = this.el.elements.startsAt;

        if (!field || !field.value) {
            return null;
        }

        const startsAt = new Date(field.value);

        return Number.isNaN(startsAt.getTime()) ? null : startsAt;
    }

    _getQuantity() {
        const field = this.el.elements.quantity;
        const quantity = field ? Number.parseInt(field.value, 10) : 0;

        return quantity > 0 ? quantity : null;
    }

    _setLoading(isLoading) {
        if (this._submitButton) {
            this._submitButton.disabled = isLoading;
        }
    }

    _showError(message) {
        if (!this._errorElement) {
            return;
        }

        this._errorElement.textContent = message;
        this._errorElement.classList.remove('d-none');
    }

    _hideError() {
        if (!this._errorElement) {
            return;
        }

        this._errorElement.textContent = '';
        this._errorElement.classList.add('d-none');
    }
}
