(() => {
  const form = document.querySelector("[data-diamond-order-form]");
  const packageSelect = document.querySelector("[data-diamond-package]");
  const packageStatus = document.querySelector("[data-diamond-package-status]");
  const playerInput = document.querySelector("#player-id");
  const paymentReferenceInput = document.querySelector("#payment-reference");
  const jordanPaymentPanel = document.querySelector("[data-jordan-payment-instructions]");
  const jordanPaymentAmount = jordanPaymentPanel?.querySelector("[data-jordan-payment-amount]");
  const jordanPaymentMethods = jordanPaymentPanel?.querySelector("[data-jordan-payment-methods]");
  const jordanPaymentSetupWarning = jordanPaymentPanel?.querySelector("[data-jordan-payment-setup-warning]");
  const review = document.querySelector("[data-diamond-review]");
  const confirmation = document.querySelector("[data-diamond-confirmation]");
  const orders = document.querySelector("[data-diamond-orders]");
  const accountMessage = document.querySelector("[data-diamond-account-message]");
  const loginLink = document.querySelector("[data-diamond-login]");
  if (
    !(form instanceof HTMLFormElement)
    || !(packageSelect instanceof HTMLSelectElement)
    || !(packageStatus instanceof HTMLElement)
    || !(playerInput instanceof HTMLInputElement)
    || !(paymentReferenceInput instanceof HTMLInputElement)
    || !(jordanPaymentPanel instanceof HTMLElement)
    || !(jordanPaymentAmount instanceof HTMLElement)
    || !(jordanPaymentMethods instanceof HTMLElement)
    || !(jordanPaymentSetupWarning instanceof HTMLElement)
    || !(review instanceof HTMLElement)
    || !(confirmation instanceof HTMLElement)
    || !(orders instanceof HTMLElement)
    || !(accountMessage instanceof HTMLElement)
  ) return;

  const errorMessage = form.querySelector("[data-diamond-error]");
  const reviewError = review.querySelector("[data-diamond-review-error]");
  const submitButton = review.querySelector("[data-diamond-submit]");
  const selectedSummary = {
    package: review.querySelector("[data-diamond-review-package]"),
    amount: review.querySelector("[data-diamond-review-amount]"),
    price: review.querySelector("[data-diamond-review-price]"),
    player: review.querySelector("[data-diamond-review-player]"),
    reference: review.querySelector("[data-diamond-review-reference]"),
  };
  if (
    !(errorMessage instanceof HTMLElement)
    || !(reviewError instanceof HTMLElement)
    || !(submitButton instanceof HTMLButtonElement)
    || Object.values(selectedSummary).some((element) => !(element instanceof HTMLElement))
  ) return;

  let packages = [];
  let pendingIdempotencyKey = null;
  let isSignedIn = false;
  let requestInFlight = false;
  const jordanWalletMethods = Array.isArray(window.NEEN_TOPUP_CONFIG?.paymentMethods)
    ? window.NEEN_TOPUP_CONFIG.paymentMethods.filter(
      (method) => method?.countryId === "JO" && method.enabled === true,
    )
    : [];

  const request = async (path, options = {}) => {
    const response = await fetch(path, {
      ...options,
      credentials: "same-origin",
      cache: "no-store",
      headers: {
        Accept: "application/json",
        ...(options.body ? { "Content-Type": "application/json" } : {}),
        ...(options.headers ?? {}),
      },
    });
    let result;
    try {
      result = await response.json();
    } catch {
      throw new Error("The recharge service returned an unreadable response.");
    }
    if (!response.ok || result.success !== true) {
      const error = result.errors?.[0];
      const failure = new Error(
        typeof error?.message === "string" ? error.message : `Request failed (${response.status}).`,
      );
      failure.code = typeof error?.code === "string" ? error.code : "";
      failure.status = response.status;
      throw failure;
    }
    return result.data;
  };

  const setError = (element, text) => {
    element.textContent = text;
    element.hidden = false;
  };

  const clearError = (element) => {
    element.textContent = "";
    element.hidden = true;
  };

  const selectedPackage = () => packages.find((item) => String(item.id) === packageSelect.value);

  const formatPrice = (value, currencyCode) => {
    const match = /^([0-9]+)(?:\.([0-9]{1,2}))?$/.exec(String(value));
    const amount = match ? `${match[1]}.${(match[2] ?? "").padEnd(2, "0")}` : String(value);
    return `${amount} ${currencyCode}`;
  };

  const renderJordanPaymentInstructions = () => {
    const item = selectedPackage();
    jordanPaymentPanel.hidden = item?.currency_code !== "JOD";
    jordanPaymentMethods.replaceChildren();
    jordanPaymentSetupWarning.hidden = true;
    if (jordanPaymentPanel.hidden) return;

    jordanPaymentAmount.textContent = formatPrice(item.price, item.currency_code);
    let hasReadyMethod = false;
    for (const method of jordanWalletMethods) {
      const row = document.createElement("div");
      const title = document.createElement("dt");
      title.textContent = `${method.name} / ${method.localName}`;
      const details = document.createElement("dd");
      const identifierIsSet =
        typeof method.paymentIdentifier === "string" && method.paymentIdentifier.trim() !== "";
      const beneficiaryIsSet =
        method.beneficiaryRequired !== true
        || (typeof method.beneficiaryName === "string" && method.beneficiaryName.trim() !== "");
      const methodIsReady = method.setupRequired !== true && identifierIsSet && beneficiaryIsSet;

      if (methodIsReady) {
        hasReadyMethod = true;
        details.textContent = `Recipient / المستلم: ${method.paymentIdentifier}\nBeneficiary / اسم المستفيد: ${method.beneficiaryName || "Not required"}`;
      } else {
        details.textContent = "Recipient details are not configured yet. / لم يتم إعداد بيانات المستلم بعد.";
      }

      if (Array.isArray(method.instructions)) {
        details.textContent += `\n${method.instructions.join("\n")}`;
      }
      if (typeof method.confirmationMethod === "string" && method.confirmationMethod.trim() !== "") {
        details.textContent += `\n${method.confirmationMethod}`;
      }
      row.append(title, details);
      jordanPaymentMethods.append(row);
    }

    if (!hasReadyMethod) {
      jordanPaymentSetupWarning.textContent =
        "No Jordan transfer method is ready. Do not send money until a recipient is configured in payment-config.js. / لا توجد طريقة تحويل أردنية جاهزة. لا تحوّل أي مبلغ قبل إعداد بيانات المستلم في payment-config.js.";
      jordanPaymentSetupWarning.hidden = false;
    }
  };

  const loadPackages = async () => {
    const { packages: activePackages } = await request("/api/diamonds/packages");
    if (!Array.isArray(activePackages)) throw new Error("The server package catalog is invalid.");
    packages = activePackages;
    packageSelect.replaceChildren(new Option("Select a package", ""));
    for (const item of packages) {
      const label = `${item.name} · ${item.diamond_amount} Diamonds · ${formatPrice(item.price, item.currency_code)}`;
      packageSelect.add(new Option(label, String(item.id)));
    }
    packageSelect.disabled = packages.length === 0;
    packageStatus.textContent = packages.length === 0
      ? "No active diamond packages are available."
      : `${packages.length} active server-configured package${packages.length === 1 ? "" : "s"}.`;
    form.querySelector('button[type="submit"]').disabled = packages.length === 0;
    renderJordanPaymentInstructions();
  };

  const renderOrders = (items) => {
    if (!Array.isArray(items) || items.length === 0) {
      orders.replaceChildren(Object.assign(document.createElement("p"), {
        className: "admin-state",
        textContent: "No recharge requests are recorded for this account.",
      }));
      return;
    }
    const fragment = document.createDocumentFragment();
    for (const order of items) {
      const card = document.createElement("article");
      card.className = "diamond-order-card";
      const heading = document.createElement("div");
      heading.className = "diamond-order-heading";
      const number = document.createElement("strong");
      number.textContent = order.order_number;
      const status = document.createElement("span");
      status.className = "admin-status-pill";
      status.textContent = order.order_status.replaceAll("_", " ");
      heading.append(number, status);
      const detail = document.createElement("p");
      detail.textContent = `${order.package_name} · ${order.diamond_amount} Diamonds · ${formatPrice(order.price, order.currency_code)} · Player ID ${order.player_id}`;
      const states = document.createElement("small");
      states.textContent = `Payment: ${order.payment_status.replaceAll("_", " ")} · Delivery: ${order.delivery_status.replaceAll("_", " ")}`;
      card.append(heading, detail, states);
      if (order.payment_reference) {
        const reference = document.createElement("small");
        reference.textContent = `Payment reference: ${order.payment_reference}`;
        card.append(reference);
      }
      fragment.append(card);
    }
    orders.replaceChildren(fragment);
  };

  const loadOrders = async () => {
    try {
      const { orders: accountOrders } = await request("/api/diamonds/orders");
      isSignedIn = true;
      accountMessage.textContent = "Your account-bound recharge requests and current statuses.";
      if (loginLink instanceof HTMLAnchorElement) loginLink.hidden = true;
      renderOrders(accountOrders);
      form.querySelector('button[type="submit"]').disabled = packages.length === 0;
    } catch (error) {
      if (error?.code === "AUTH_REQUIRED") {
        isSignedIn = false;
        accountMessage.textContent = "Sign in to submit and track a recharge request.";
        if (loginLink instanceof HTMLAnchorElement) loginLink.hidden = false;
        orders.replaceChildren();
        form.querySelector('button[type="submit"]').disabled = true;
        return;
      }
      orders.replaceChildren(Object.assign(document.createElement("p"), {
        className: "admin-state is-error",
        textContent: error instanceof Error ? error.message : "Recharge orders could not be loaded.",
      }));
    }
  };

  packageSelect.addEventListener("change", renderJordanPaymentInstructions);

  form.addEventListener("submit", (event) => {
    event.preventDefault();
    clearError(errorMessage);
    if (!form.reportValidity()) return;
    if (!isSignedIn) {
      setError(errorMessage, "Sign in to your NEEN account before submitting an order.");
      return;
    }
    const item = selectedPackage();
    if (!item) {
      setError(errorMessage, "Choose an active package from the server catalog.");
      return;
    }
    selectedSummary.package.textContent = item.name;
    selectedSummary.amount.textContent = String(item.diamond_amount);
    selectedSummary.price.textContent = formatPrice(item.price, item.currency_code);
    selectedSummary.player.textContent = playerInput.value;
    selectedSummary.reference.textContent = paymentReferenceInput.value.trim() || "Not provided";
    submitButton.disabled = false;
    form.hidden = true;
    review.hidden = false;
    review.focus?.();
  });

  review.querySelector("[data-diamond-edit]")?.addEventListener("click", () => {
    review.hidden = true;
    form.hidden = false;
  });

  submitButton.addEventListener("click", async () => {
    if (requestInFlight || !isSignedIn) return;
    clearError(reviewError);
    requestInFlight = true;
    submitButton.disabled = true;
    try {
      const csrf = await request("/api/auth/csrf");
      if (typeof csrf.csrf_token !== "string") throw new Error("A CSRF token is unavailable. Sign in again.");
      pendingIdempotencyKey ??= crypto.randomUUID();
      const result = await request("/api/diamonds/orders", {
        method: "POST",
        headers: { "X-CSRF-Token": csrf.csrf_token },
        body: JSON.stringify({
          package_id: Number(packageSelect.value),
          player_id: playerInput.value,
          payment_reference: paymentReferenceInput.value.trim() || null,
          idempotency_key: pendingIdempotencyKey,
        }),
      });
      pendingIdempotencyKey = null;
      review.hidden = true;
      confirmation.hidden = false;
      confirmation.querySelector("[data-diamond-order-number]").textContent = result.order.order_number;
      confirmation.querySelector("[data-diamond-confirmation-copy]").textContent =
        `Request status: ${result.order.order_status}. Payment verification and delivery are still pending.`;
      confirmation.querySelector("#confirmation-title").focus();
      await loadOrders();
    } catch (error) {
      setError(reviewError, error instanceof Error ? error.message : "The recharge request could not be submitted.");
    } finally {
      requestInFlight = false;
      submitButton.disabled = false;
    }
  });

  document.querySelector("[data-diamond-refresh]")?.addEventListener("click", loadOrders);
  Promise.all([
    loadPackages().catch((error) => {
      packageSelect.disabled = true;
      form.querySelector('button[type="submit"]').disabled = true;
      packageStatus.textContent = error instanceof Error ? error.message : "Package catalog is unavailable.";
    }),
    loadOrders(),
  ]);

  // --- NEEN Coins Top-Up Section ---
  const coinForm = document.querySelector("[data-coin-topup-form]");
  const coinPackageSelect = document.querySelector("[data-coin-topup-package]");
  const coinMethodSelect = document.querySelector("[data-coin-topup-method]");
  const coinInstructions = document.querySelector("[data-coin-topup-instructions]");
  const coinStatus = document.querySelector("[data-coin-topup-status]");
  const coinError = document.querySelector("[data-coin-topup-error]");
  const coinSuccess = document.querySelector("[data-coin-topup-success]");
  if (
    coinForm instanceof HTMLFormElement
    && coinPackageSelect instanceof HTMLSelectElement
    && coinMethodSelect instanceof HTMLSelectElement
  ) {
    let coinPackages = [];
    let coinMethods = [];
    let coinInFlight = false;

    const updateCoinMethodInfo = () => {
      const m = coinMethods.find((item) => String(item.id) === coinMethodSelect.value);
      if (coinInstructions) {
        coinInstructions.textContent = m?.instructions || "Select a payment method to view transfer instructions.";
      }
    };
    coinMethodSelect.addEventListener("change", updateCoinMethodInfo);

    Promise.all([
      request("/api/coins/packages"),
      request("/api/coins/payment-methods"),
    ]).then(([pkgData, methodData]) => {
      coinPackages = Array.isArray(pkgData.packages) ? pkgData.packages : [];
      coinMethods = Array.isArray(methodData.payment_methods) ? methodData.payment_methods : [];
      coinPackageSelect.replaceChildren(new Option("Select a Coin package", ""));
      for (const p of coinPackages) {
        coinPackageSelect.add(new Option(`${p.name} · ${p.coin_amount} Coins · ${formatPrice(p.price, p.currency_code)}`, String(p.id)));
      }
      coinMethodSelect.replaceChildren(new Option("Select payment method", ""));
      for (const m of coinMethods) {
        coinMethodSelect.add(new Option(m.display_name, String(m.id)));
      }
      const ready = coinPackages.length > 0 && coinMethods.length > 0;
      coinPackageSelect.disabled = !ready;
      coinMethodSelect.disabled = !ready;
      const submitBtn = coinForm.querySelector('button[type="submit"]');
      if (submitBtn) submitBtn.disabled = !ready;
      if (coinStatus) {
        coinStatus.textContent = ready
          ? `${coinPackages.length} active Coin package(s) available.`
          : "Coin packages or payment methods are not configured yet.";
      }
      updateCoinMethodInfo();
    }).catch((err) => {
      if (coinStatus) coinStatus.textContent = err instanceof Error ? err.message : "Could not load Coin packages.";
    });

    coinForm.addEventListener("submit", async (e) => {
      e.preventDefault();
      if (coinInFlight) return;
      if (coinError) { coinError.hidden = true; coinError.textContent = ""; }
      if (coinSuccess) { coinSuccess.hidden = true; coinSuccess.textContent = ""; }
      if (!isSignedIn) {
        if (coinError) { coinError.textContent = "Sign in to your NEEN account before purchasing Coins."; coinError.hidden = false; }
        return;
      }
      const pkgId = Number(coinPackageSelect.value);
      const methodId = Number(coinMethodSelect.value);
      if (!pkgId || !methodId) {
        if (coinError) { coinError.textContent = "Choose both a Coin package and a payment method."; coinError.hidden = false; }
        return;
      }
      const refInput = coinForm.querySelector("[data-coin-topup-ref]");
      const proofInput = coinForm.querySelector("[data-coin-topup-proof]");
      const paymentRef = refInput ? refInput.value.trim() : "";
      const proofDetails = proofInput ? proofInput.value.trim() : "";
      coinInFlight = true;
      const btn = coinForm.querySelector('button[type="submit"]');
      if (btn) btn.disabled = true;
      try {
        const csrf = await request("/api/auth/csrf");
        const orderRes = await request("/api/coins/orders", {
          method: "POST",
          headers: { "X-CSRF-Token": csrf.csrf_token },
          body: JSON.stringify({
            package_id: pkgId,
            payment_method_id: methodId,
            idempotency_key: crypto.randomUUID(),
          }),
        });
        const orderId = orderRes.order?.id;
        if (orderId && (paymentRef || proofDetails)) {
          await request(`/api/coins/orders/${orderId}/evidence`, {
            method: "POST",
            headers: { "X-CSRF-Token": csrf.csrf_token },
            body: JSON.stringify({
              payment_reference: paymentRef || null,
              proof_details: proofDetails || null,
              idempotency_key: crypto.randomUUID(),
            }),
          });
        }
        coinForm.reset();
        updateCoinMethodInfo();
        if (coinSuccess) {
          coinSuccess.textContent = `Coin order #${orderId} submitted! Track status in your Account page.`;
          coinSuccess.hidden = false;
        }
      } catch (err) {
        if (coinError) {
          coinError.textContent = err instanceof Error ? err.message : "Could not submit Coin purchase order.";
          coinError.hidden = false;
        }
      } finally {
        coinInFlight = false;
        if (btn) btn.disabled = false;
      }
    });
  }
})();
