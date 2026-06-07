# Mobile scanner app — Flutter (iOS/Android) operator app (apps/scanner-mobile).
# Local dev/simulation targets; the CI build lives in .github/scripts/build-*.sh.
#
# Platform-independent toolchain: if no system `flutter` is on PATH, a pinned
# Flutter SDK is git-cloned into $(FLUTTER_SDK_DIR) and used automatically — no
# PATH change, no package manager, works on macOS/Linux/Windows (git-bash/WSL).
# Only the OS-specific BUILD toolchains (Xcode on macOS, the Android SDK) can't
# be made universal; `make mobile-doctor` reports what each host still needs.

MOBILE_DIR      := $(PLUGIN_DIR)/apps/scanner-mobile
FLUTTER_CHANNEL ?= stable
FLUTTER_SDK_DIR ?= $(HOME)/.flutter-sdk
# Prefer a system Flutter; otherwise fall back to the bootstrapped SDK path.
FLUTTER := $(shell command -v flutter 2>/dev/null || echo "$(FLUTTER_SDK_DIR)/bin/flutter")

.PHONY: mobile-install _mobile-fetch-sdk _mobile-require _mobile-platform-hint \
        mobile-doctor mobile-setup mobile-deps mobile-test mobile-analyze \
        mobile-devices mobile-run mobile-ios mobile-android mobile-apk mobile-clean

##@ Mobile scanner (Flutter)

mobile-install: ## Install a pinned Flutter SDK (git clone — works on any OS, asks first)
	@if command -v flutter >/dev/null 2>&1; then \
		echo "✅ System Flutter already present: $$(command -v flutter)"; exit 0; \
	fi
	@command -v git >/dev/null 2>&1 || { echo "❌ git required: https://git-scm.com"; exit 1; }
	@printf "Clone the Flutter %s SDK into %s ? [y/N] " "$(FLUTTER_CHANNEL)" "$(FLUTTER_SDK_DIR)"; \
	read ans; case "$$ans" in [yY]*) ;; *) echo "Aborted."; exit 1 ;; esac
	@$(MAKE) _mobile-fetch-sdk

# Raw SDK fetch (no prompt) — invoked only after a confirmation.
_mobile-fetch-sdk:
	@command -v git >/dev/null 2>&1 || { echo "❌ git required: https://git-scm.com"; exit 1; }
	@if [ -x "$(FLUTTER_SDK_DIR)/bin/flutter" ]; then \
		echo "✅ Flutter SDK already at $(FLUTTER_SDK_DIR)"; \
	else \
		echo "▶ Cloning Flutter $(FLUTTER_CHANNEL) into $(FLUTTER_SDK_DIR) ..."; \
		git clone --depth 1 -b $(FLUTTER_CHANNEL) https://github.com/flutter/flutter.git "$(FLUTTER_SDK_DIR)"; \
	fi
	@"$(FLUTTER_SDK_DIR)/bin/flutter" --version
	@$(MAKE) _mobile-platform-hint

# Ensures a usable flutter exists; offers the portable install if missing.
# Non-TTY safe (won't hang in CI/pipes).
_mobile-require:
	@if command -v flutter >/dev/null 2>&1 || [ -x "$(FLUTTER)" ]; then :; else \
		echo "❌ Flutter not found."; \
		if [ -t 0 ]; then \
			printf "Install a pinned Flutter SDK now (git clone, any OS)? [y/N] "; \
			read ans; case "$$ans" in [yY]*) $(MAKE) _mobile-fetch-sdk ;; \
				*) echo "Run 'make mobile-install' later."; exit 1 ;; esac; \
		else \
			echo "   Run: make mobile-install"; exit 1; \
		fi; \
	fi

_mobile-platform-hint:
	@os="$$(uname -s 2>/dev/null || echo unknown)"; \
	echo ""; echo "ℹ️  Flutter ready. OS-specific BUILD toolchains still needed:"; \
	case "$$os" in \
		Darwin) echo "   • iOS:     Xcode (App Store) + cocoapods (brew install cocoapods)"; \
		        echo "   • Android: Android Studio (brew install --cask android-studio)";; \
		Linux)  echo "   • Android: Android Studio or Android cmdline-tools + JDK 17 (no iOS on Linux)";; \
		MINGW*|MSYS*|CYGWIN*) echo "   • Android: Android Studio for Windows (no iOS on Windows)";; \
		*)      echo "   • See https://docs.flutter.dev/get-started/install";; \
	esac; \
	echo "   Then verify with: make mobile-doctor"

mobile-doctor: _mobile-require ## Toolchain diagnostics (flutter doctor)
	@"$(FLUTTER)" doctor

mobile-setup: _mobile-require ## One-time: generate platform shells + deps + camera permission
	@if [ ! -d $(MOBILE_DIR)/ios ] && [ ! -d $(MOBILE_DIR)/android ]; then \
		cd $(MOBILE_DIR) && "$(FLUTTER)" create --org org.fibbooking --project-name booking_scanner .; \
	fi
	@cd $(MOBILE_DIR) && "$(FLUTTER)" pub get
	@if [ -d $(MOBILE_DIR)/android ]; then cd $(MOBILE_DIR) && tool/patch_permissions.sh android; fi
	@if [ -d $(MOBILE_DIR)/ios ] && [ "$$(uname)" = "Darwin" ]; then cd $(MOBILE_DIR) && tool/patch_permissions.sh ios; fi
	@echo "✅ Mobile scanner ready. Run it with: make mobile-run"

mobile-deps: _mobile-require ## Resolve Dart/Flutter dependencies (flutter pub get)
	@cd $(MOBILE_DIR) && "$(FLUTTER)" pub get

mobile-test: mobile-deps ## Run the Dart unit tests (pure logic — no device needed)
	@cd $(MOBILE_DIR) && "$(FLUTTER)" test
	@echo "✅ Mobile scanner tests passed"

mobile-analyze: mobile-deps ## Static analysis (flutter analyze)
	@cd $(MOBILE_DIR) && "$(FLUTTER)" analyze

mobile-devices: _mobile-require ## List available simulators/emulators/devices
	@"$(FLUTTER)" devices

mobile-run: mobile-setup ## Run on a device/simulator/emulator (DEVICE=<id> to target one)
	@echo "▶ Point the app at a valid-HTTPS backend — e.g. 'make scanner-ngrok' gives one."
	@cd $(MOBILE_DIR) && "$(FLUTTER)" run $(if $(DEVICE),-d $(DEVICE),)

mobile-ios: mobile-setup ## Boot the iOS Simulator (macOS) and run the app there
	@[ "$$(uname)" = "Darwin" ] || { echo "❌ iOS Simulator is macOS-only. Use 'make mobile-android'."; exit 1; }
	@open -a Simulator
	@cd $(MOBILE_DIR) && "$(FLUTTER)" run -d iphone

mobile-android: mobile-setup ## Run on a started Android emulator / connected device
	@cd $(MOBILE_DIR) && "$(FLUTTER)" run -d android

mobile-apk: mobile-setup ## Build a release APK locally (debug-key default — installs out of the box)
	@cd $(MOBILE_DIR) && "$(FLUTTER)" build apk --release
	@echo "✅ APK: $(MOBILE_DIR)/build/app/outputs/flutter-apk/app-release.apk"

mobile-clean: ## Remove generated platform shells + Dart build output (keeps the SDK)
	@cd $(MOBILE_DIR) && "$(FLUTTER)" clean >/dev/null 2>&1 || true
	@rm -rf $(MOBILE_DIR)/android $(MOBILE_DIR)/ios $(MOBILE_DIR)/linux \
		$(MOBILE_DIR)/macos $(MOBILE_DIR)/windows $(MOBILE_DIR)/web \
		$(MOBILE_DIR)/.dart_tool $(MOBILE_DIR)/.metadata
	@echo "✅ Mobile scanner build artifacts removed"
