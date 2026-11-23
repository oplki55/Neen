(() => {
  const message = document.querySelector("[data-account-message]");
  const authPanel = document.querySelector("[data-auth-panel]");
  const verificationNotice = document.querySelector("[data-email-verification-notice]");
  const verificationNoticeCopy = document.querySelector("[data-email-verification-copy]");
  const resendVerificationButton = document.querySelector("[data-resend-verification]");
  let pendingVerificationIdentifier = "";
  const profilePanel = document.querySelector("[data-profile-panel]");
  const tabs = document.querySelector("[data-auth-tabs]");
  const forms = {
    login: document.querySelector("#login-form"),
    register: document.querySelector("#register-form"),
    forgot: document.querySelector("#forgot-form"),
    reset: document.querySelector("#reset-form"),
    profile: document.querySelector("#profile-form"),
    passwordChange: document.querySelector("#password-change-form"),
  };
  const attendanceButton = document.querySelector("[data-attendance-checkin]");
  const attendanceStatus = profilePanel.querySelector("[data-attendance-status]");
  const profileTabButtons = Array.from(document.querySelectorAll("[data-profile-tab]"));
  const avatarInput = forms.profile.elements.avatar_url;
  const avatarPreview = profilePanel.querySelector("[data-avatar-preview]");
  const heroAvatar = profilePanel.querySelector("[data-hero-avatar]");
  const freeFireIdWrapper = profilePanel.querySelector("[data-free-fire-id-wrapper]");
  const freeFireIdValue = profilePanel.querySelector("[data-free-fire-id]");
  const gameAttemptsNode = profilePanel.querySelector("[data-game-attempts]");
  const gameAttemptsState = profilePanel.querySelector("[data-game-attempts-state]");
  const codexGrid = profilePanel.querySelector("[data-island-codex]");
  const codexProgress = profilePanel.querySelector("[data-codex-progress]");
  const DEFAULT_AVATAR = "icone/snoop.webp";
  const AVATAR_PRESETS = [
    DEFAULT_AVATAR,
    ...Array.from({ length: 9 }, (_, index) => `assets/hero-loot/snoop-sticker-${String(index + 1).padStart(2, "0")}.webp`),
  ];
  const GAME_CARDS = [
    { title: "Memory Match", catalog: "games/memory-match", current: "games/memory-match/sessions/current", href: "memory-match.html" },
    { title: "Picture Jigsaw", catalog: "games/picture-jigsaw", current: "games/picture-jigsaw/sessions/current", href: "picture-jigsaw.html" },
    { title: "Target Rush", catalog: "games/target-rush", current: "games/target-rush/sessions/current", href: "target-rush.html" },
    { title: "Loot Sequence Strike", catalog: "games/loot-sequence", current: "games/loot-sequence/sessions/current", href: "loot-sequence.html" },
  ];
  let csrfToken = "";
  let csrfPromise = null;
  let currentProfile = null;
  let resetToken = new URLSearchParams(window.location.hash.slice(1)).get("reset_token") || "";
  let verificationToken = new URLSearchParams(window.location.hash.slice(1)).get("verify_token") || "";

  const showMessage = (text, isError = false) => {
    message.textContent = text;
    message.classList.toggle("is-error", isError);
    message.classList.toggle("is-success", !isError && text !== "");
  };

  const showVerificationNotice = (identifier, text) => {
    pendingVerificationIdentifier = identifier;
    verificationNoticeCopy.textContent = text || "Verify your email within 48 hours before signing in.";
    verificationNotice.hidden = false;
  };

  const api = async (path, method = "GET", data = null) => {
    const options = {
      method,
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    };
    if (data !== null) {
      options.headers["Content-Type"] = "application/json";
      options.headers["X-CSRF-Token"] = await ensureCsrf();
      options.body = JSON.stringify(data);
    }
    const response = await fetch(`/api/${path}`, options);
    let result;
    try {
      result = await response.json();
    } catch {
      throw new Error("The account service returned an invalid response.");
    }
    if (!response.ok || !result.success) {
      const error = new Error(result.errors?.[0]?.message || "The request could not be completed.");
      error.code = result.errors?.[0]?.code;
      error.status = response.status;
      throw error;
    }
    return result.data;
  };

  const requestCsrfToken = async () => {
    const result = await api("auth/csrf");
    if (typeof result.csrf_token !== "string" || result.csrf_token === "") {
      throw new Error("The account service did not provide a CSRF token.");
    }
    csrfToken = result.csrf_token;
    return csrfToken;
  };

  const ensureCsrf = async () => {
    if (csrfToken !== "") return csrfToken;
    if (csrfPromise === null) {
      csrfPromise = requestCsrfToken().finally(() => {
        csrfPromise = null;
      });
    }
    return csrfPromise;
  };

  const scrollDocumentToTop = () => {
    const scrollElement = document.scrollingElement;
    if (scrollElement) scrollElement.scrollTop = 0;
  };

  const scrollAccountPageToTop = () => {
    if (window.matchMedia("(max-width: 760px)").matches) {
      scrollDocumentToTop();
    }
  };

  window.addEventListener("pageshow", () => {
    scrollAccountPageToTop();
    window.setTimeout(scrollAccountPageToTop, 500);
  });

  const setMode = (mode) => {
    const activeElement = document.activeElement;
    if (
      activeElement instanceof HTMLElement
      && Object.values(forms).some((form) => form !== forms[mode] && form.contains(activeElement))
    ) {
      activeElement.blur();
    }
    Object.entries(forms).forEach(([name, form]) => {
      if (name !== "profile") form.hidden = name !== mode;
    });
    if (mode !== "login") verificationNotice.hidden = true;
    tabs.hidden = mode === "forgot" || mode === "reset";
    tabs.querySelectorAll("[data-mode]").forEach((button) => {
      const selected = button.dataset.mode === mode;
      button.classList.toggle("is-active", selected);
      button.setAttribute("aria-pressed", String(selected));
    });
    showMessage("");
    scrollAccountPageToTop();
    window.requestAnimationFrame(scrollAccountPageToTop);
  };

  const element = (tagName, className, text) => {
    const node = document.createElement(tagName);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  };

  const formatCoins = (value) => new Intl.NumberFormat().format(value);
  const formatDate = (value) => new Date(`${value.replace(" ", "T")}Z`).toLocaleString();
  const idempotencyKey = () => crypto.randomUUID();
  const presetAvatarPaths = new Set(AVATAR_PRESETS);

  const safeAvatarSource = (value) => {
    if (typeof value !== "string" || value.trim() === "") return DEFAULT_AVATAR;
    const candidate = value.trim();
    if (presetAvatarPaths.has(candidate)) return candidate;
    try {
      const url = new URL(candidate);
      return url.protocol === "https:" ? url.href : DEFAULT_AVATAR;
    } catch {
      return DEFAULT_AVATAR;
    }
  };

  const setAvatarImage = (image, value) => {
    const source = safeAvatarSource(value);
    image.onerror = () => {
      image.onerror = null;
      image.src = DEFAULT_AVATAR;
    };
    image.src = source;
  };

  const selectProfileTab = (name, focus = false) => {
    profileTabButtons.forEach((button) => {
      const selected = button.dataset.profileTab === name;
      button.classList.toggle("is-active", selected);
      button.setAttribute("aria-selected", String(selected));
      button.tabIndex = selected ? 0 : -1;
      if (selected && focus) button.focus();
    });
    profilePanel.querySelectorAll("[data-profile-content]").forEach((panel) => {
      panel.hidden = panel.dataset.profileContent !== name;
    });
  };

  const renderPlayerHeader = (profile) => {
    profilePanel.querySelector("[data-hero-display-name]").textContent =
      profile.display_name || profile.username || "Player";
    profilePanel.querySelector("[data-hero-username]").textContent = `@${profile.username}`;
    profilePanel.querySelector("[data-hero-country]").textContent = profile.country || "NEEN PLAYER";
    profilePanel.querySelector("[data-hero-bio]").textContent = profile.bio || "Your next adventure starts here.";
    const verification = element(
      "span",
      `gamer-verification${profile.email_verified ? " is-verified" : ""}`,
      profile.email_verified ? "✓ Email verified" : "Email not verified",
    );
    const identity = profilePanel.querySelector("[data-identity]");
    identity.replaceChildren(
      element("span", "gamer-email", profile.email),
      verification,
    );
    setAvatarImage(heroAvatar, profile.avatar_url);
    setAvatarImage(avatarPreview, profile.avatar_url);
    profilePanel.querySelector("[data-security-email-state]").textContent = profile.email_verified
      ? "Your email address is verified."
      : "Your email is not verified yet. Confirm it within 48 hours to keep your account fully verified.";
    profilePanel.querySelector("[data-resend-profile-verification]").hidden = profile.email_verified;
    freeFireIdWrapper.hidden = !profile.free_fire_id;
    freeFireIdValue.textContent = profile.free_fire_id || "";
    avatarInput.value = profile.avatar_url || "";
    profilePanel.querySelectorAll("[data-avatar-preset]").forEach((button) => {
      const selected = button.dataset.avatarPreset === profile.avatar_url;
      button.classList.toggle("is-selected", selected);
      button.setAttribute("aria-pressed", String(selected));
    });
  };

  const safeCodexImage = (value) => {
    if (typeof value !== "string") return null;
    const path = value.replace(/^\/+/, "");
    if (
      !/^assets\/hero-loot\/[A-Za-z0-9._/-]+$/.test(path)
      || path.split("/").includes("..")
    ) return null;
    return `/${path}`;
  };

  const renderIslandCodex = (catalog, progress) => {
    const collectibles = Array.isArray(catalog) ? catalog : [];
    const unlocked = new Set(Array.isArray(progress?.unlocked_collectibles) ? progress.unlocked_collectibles : []);
    codexGrid.replaceChildren();
    collectibles.forEach((item) => {
      const isUnlocked = unlocked.has(item.id);
      const card = element("article", `codex-card${isUnlocked ? " is-unlocked" : " is-locked"}`);
      const art = element("span", "codex-art");
      const source = safeCodexImage(item.image);
      if (source) {
        const image = element("img");
        image.src = source;
        image.alt = isUnlocked ? `${item.name} collectible` : "Undiscovered collectible silhouette";
        image.loading = "lazy";
        image.decoding = "async";
        art.append(image);
      }
      const details = document.createElement("div");
      details.append(
        element("h4", "", isUnlocked ? item.name : "Undiscovered item"),
        element("p", "", isUnlocked ? `${item.rarity || "Island collectible"} · Discovered` : "Explore Sunny Island to discover this item."),
      );
      card.append(art, details);
      codexGrid.append(card);
    });
    const count = unlocked.size;
    codexProgress.textContent = `${count} / ${collectibles.length} discovered`;
    if (collectibles.length === 0) {
      codexProgress.textContent = "The Island Codex is currently unavailable.";
      codexGrid.append(element("p", "profile-data-state", "No collectible catalog is available right now."));
    }
  };

  const fetchGameCard = async (config) => {
    const catalogData = await api(config.catalog);
    const game = catalogData.game;
    if (!game || typeof game.status !== "string") {
      throw new Error("The game catalog returned incomplete data.");
    }
    if (game.status !== "active") {
      return { config, game, remaining: 0, inactive: true };
    }
    const currentData = await api(config.current);
    const remaining = Number(currentData.attempts_remaining_today);
    if (!Number.isInteger(remaining) || remaining < 0) {
      throw new Error("The game attempt count is unavailable.");
    }
    return { config, game, remaining, inactive: false };
  };

  const renderGameCard = (result, config) => {
    const card = element("article", "profile-game-card");
    card.append(element("h4", "", config.title));
    if (result.status === "rejected") {
      const reason = result.reason instanceof Error ? result.reason.message : "The request could not be completed.";
      card.append(element("p", "profile-game-meta", `Game status unavailable: ${reason}`));
      gameAttemptsNode.append(card);
      return false;
    }
    const { game, remaining, inactive } = result.value;
    card.append(
      element(
        "p",
        "profile-game-meta",
        inactive
          ? "This game is not available right now."
          : `${remaining} of ${game.free_attempts_per_day} free starts left today · ${formatCoins(game.reward_amount_coins)} Earned Coins per win`,
      ),
    );
    if (!inactive) {
      const link = element("a", "", `Play ${config.title} ↗`);
      link.href = config.href;
      card.append(link);
    }
    gameAttemptsNode.append(card);
    return true;
  };

  const renderSunnyIsland = (result) => {
    const island = result.status === "fulfilled" ? result.value.state : null;
    const progress = island?.progress;
    if (!progress || !island.adventure) {
      const reason = result.status === "rejected" && result.reason instanceof Error
        ? result.reason.message
        : "Sunny Island returned incomplete profile data.";
      profilePanel.querySelector("[data-island-xp]").textContent = "Progress unavailable";
      profilePanel.querySelector("[data-stat-level]").textContent = "—";
      profilePanel.querySelector("[data-stat-xp]").textContent = "—";
      profilePanel.querySelector("[data-stat-adventures]").textContent = "—";
      profilePanel.querySelector("[data-stat-zones]").textContent = "—";
      codexProgress.textContent = "Island data could not be loaded.";
      codexGrid.replaceChildren(element("p", "profile-data-state", reason));
      return null;
    }
    const xp = Math.max(0, Math.floor(Number(progress.xp) || 0));
    const lvlInfo = xp < 2900
      ? { level: Math.floor(xp / 100) + 1, currentXp: xp % 100, requiredXp: 100 }
      : xp < 6500
        ? { level: 30 + Math.floor((xp - 2900) / 120), currentXp: (xp - 2900) % 120, requiredXp: 120 }
        : xp < 13700
          ? { level: 60 + Math.floor((xp - 6500) / 180), currentXp: (xp - 6500) % 180, requiredXp: 180 }
          : { level: 100, currentXp: 180, requiredXp: 180 };
    const level = lvlInfo.level;
    const zones = Array.isArray(progress.discovered_zones) ? progress.discovered_zones.length : 0;
    profilePanel.querySelector("[data-island-level]").textContent = String(level);
    profilePanel.querySelector("[data-stat-level]").textContent = String(level);
    profilePanel.querySelector("[data-stat-xp]").textContent = formatCoins(xp);
    profilePanel.querySelector("[data-stat-adventures]").textContent =
      formatCoins(progress.total_adventures_completed);
    profilePanel.querySelector("[data-stat-zones]").textContent = `${zones} / 4`;
    profilePanel.querySelector("[data-island-xp]").textContent = `${formatCoins(lvlInfo.currentXp)} / ${formatCoins(lvlInfo.requiredXp)} XP`;
    profilePanel.querySelector("[data-island-xp-fill]").style.width = `${Math.min(100, (lvlInfo.currentXp / lvlInfo.requiredXp) * 100)}%`;
    renderIslandCodex(island.codex, progress);
    return island;
  };

  const refreshGameProfile = async () => {
    const results = await Promise.allSettled([
      api("games/sunny-island/state"),
      ...GAME_CARDS.map(fetchGameCard),
    ]);
    const island = renderSunnyIsland(results[0]);
    gameAttemptsNode.replaceChildren();
    let loaded = island ? 1 : 0;
    if (island) {
      const adventure = island.adventure;
      const card = element("article", "profile-game-card");
      card.append(
        element("h4", "", "Sunny Island"),
        element(
          "p",
          "profile-game-meta",
          adventure.status !== "active"
            ? "This adventure is not available right now."
            : `${adventure.attempts_remaining_today} of ${adventure.free_attempts_per_day} adventures left today · ${formatCoins(adventure.reward_amount_coins)} Earned Coins per win`,
        ),
      );
      if (adventure.status === "active") {
        const link = element("a", "", "Play Sunny Island ↗");
        link.href = "sunny-island.html";
        card.append(link);
      }
      gameAttemptsNode.append(card);
    }
    GAME_CARDS.forEach((config, index) => {
      if (renderGameCard(results[index + 1], config)) loaded += 1;
    });
    const unavailable = GAME_CARDS.length + 1 - loaded;
    gameAttemptsState.textContent = unavailable === 0
      ? "Daily attempts reset on the Africa/Cairo schedule."
      : `${unavailable} game status${unavailable === 1 ? "" : "es"} could not be loaded.`;
  };

  const renderWallet = (wallet, entries) => {
    profilePanel.querySelector("[data-wallet-balance]").textContent = formatCoins(wallet.total_coin_balance);
    profilePanel.querySelector("[data-purchased-balance]").textContent = formatCoins(wallet.purchased_coin_balance);
    profilePanel.querySelector("[data-earned-balance]").textContent = formatCoins(wallet.earned_coin_balance);
    profilePanel.querySelector("[data-stat-earned-coins]").textContent = formatCoins(wallet.earned_coin_balance);
    const heroCoins = profilePanel.querySelector("[data-hero-coin-balance]");
    if (heroCoins) heroCoins.textContent = formatCoins(wallet.total_coin_balance);

    const ledger = profilePanel.querySelector("[data-coin-ledger]");
    ledger.replaceChildren();
    if (entries.length === 0) {
      ledger.append(element("li", "coin-ledger-item coin-ledger-meta", "No Coins activity yet."));
      return;
    }

    entries.forEach((entry) => {
      const item = element("li", "coin-ledger-item");
      const direction = entry.operation_type === "debit" ? "−" : "+";
      const amount = element(
        "span",
        `coin-ledger-amount ${entry.operation_type === "debit" ? "is-debit" : "is-credit"}`,
        `${direction}${formatCoins(entry.amount)} ${entry.balance_type} Coins`,
      );
      item.append(
        element("p", "coin-ledger-title", entry.source.replaceAll("_", " ")),
        amount,
        element(
          "p",
          "coin-ledger-meta",
          `${formatDate(entry.created_at)} · Total ${formatCoins(entry.total_balance_before)} → ${formatCoins(entry.total_balance_after)}`
            + (entry.note ? ` · ${entry.note}` : ""),
        ),
      );
      ledger.append(item);
    });
  };

  const renderAttendance = (attendance) => {
    profilePanel.querySelector("[data-attendance-progress]").textContent =
      `${attendance.cycle_progress} / 7`;
    profilePanel.querySelector("[data-stat-attendance]").textContent =
      `${attendance.cycle_progress} / 7`;
    const days = profilePanel.querySelector("[data-attendance-days]");
    days.replaceChildren();
    attendance.cycle_days.forEach((day) => {
      const item = element(
        "li",
        `attendance-day${day.attended ? " is-earned" : ""}${day.is_today ? " is-today" : ""}`,
      );
      item.append(
        element("span", "attendance-day-label", `DAY ${day.day}`),
        element("strong", "attendance-day-reward", day.attended ? "+100" : "100"),
        element("span", "attendance-day-state", day.attended ? "Earned" : day.day === attendance.next_cycle_day ? "Next" : "Upcoming"),
      );
      if (day.attendance_date) {
        item.append(element("time", "attendance-day-date", day.attendance_date));
      }
      days.append(item);
    });

    attendanceButton.disabled = attendance.checked_in_today;
    attendanceButton.textContent = attendance.checked_in_today
      ? "Today's attendance recorded"
      : `Check in for ${formatCoins(attendance.today_reward)} Earned Coins`;
    attendanceStatus.textContent = attendance.checked_in_today
      ? `Attendance recorded for ${attendance.today} (Africa/Cairo), cycle day ${attendance.cycle_progress} of 7.`
      : `Today is ${attendance.today} in Africa/Cairo. Check in for day ${attendance.next_cycle_day}; a missed day restarts progress but never removes earned Coins.`;
    profilePanel.querySelector("[data-wallet-balance]").textContent =
      formatCoins(attendance.wallet.coin_balance);
    profilePanel.querySelector("[data-purchased-balance]").textContent =
      formatCoins(attendance.wallet.purchased_coin_balance);
    profilePanel.querySelector("[data-earned-balance]").textContent =
      formatCoins(attendance.wallet.earned_coin_balance);
  };

  const refreshAttendance = async () => {
    const response = await api("attendance/daily");
    renderAttendance(response.attendance);
  };

  const renderCoinCatalog = (packages, methods) => {
    const form = document.querySelector("#coin-order-form");
    const message = profilePanel.querySelector("[data-coins-config-message]");
    const packageSelect = form.elements.package_id;
    const methodSelect = form.elements.payment_method_id;
    packageSelect.replaceChildren();
    methodSelect.replaceChildren();
    packages.forEach((item) => {
      const option = element(
        "option",
        "",
        `${item.name} — ${formatCoins(item.coin_amount)} Coins · ${item.price} ${item.currency_code}`,
      );
      option.value = item.id;
      packageSelect.append(option);
    });
    methods.forEach((item) => {
      const option = element("option", "", item.display_name);
      option.value = item.id;
      methodSelect.append(option);
    });

    const updateInstructions = () => {
      const method = methods.find((item) => String(item.id) === methodSelect.value);
      profilePanel.querySelector("[data-payment-instructions]").textContent =
        method?.instructions || "Payment instructions are provided after this method is configured.";
    };
    methodSelect.addEventListener("change", updateInstructions);
    if (packages.length > 0 && methods.length > 0) {
      form.hidden = false;
      message.textContent = "";
      updateInstructions();
    } else {
      form.hidden = true;
      message.textContent = "Coin packages and payment methods are not configured yet.";
    }
  };

  const renderOrders = (orders) => {
    const list = profilePanel.querySelector("[data-coin-orders]");
    list.replaceChildren();
    if (orders.length === 0) {
      list.append(element("p", "account-copy", "No Coin purchase requests yet."));
      return;
    }

    orders.forEach((order) => {
      const card = element("article", "coin-order-card");
      card.append(
        element("p", "coin-order-title", `${order.package_name} · ${formatCoins(order.coin_amount)} Coins`),
        element(
          "p",
          "coin-order-meta",
          `${order.price} ${order.currency_code} · ${order.payment_method_name} · ${order.status.replaceAll("_", " ")}`,
        ),
        element("p", "coin-order-meta", `Request #${order.id} · ${formatDate(order.created_at)}`),
      );
      if (order.payment_reference || order.proof_details) {
        card.append(element(
          "p",
          "coin-order-meta",
          `Submitted payment details${order.payment_reference ? ` · Reference: ${order.payment_reference}` : ""}`
            + (order.proof_details ? ` · ${order.proof_details}` : ""),
        ));
      }
      if (order.review_reason) {
        card.append(element("p", "coin-order-meta", `Review: ${order.review_reason}`));
      }

      if (order.status === "awaiting_payment") {
        const evidenceForm = element("form", "coin-order-evidence");
        const referenceLabel = element("label", "account-field");
        referenceLabel.append(element("span", "", "Payment reference"));
        const referenceInput = element("input");
        referenceInput.name = "payment_reference";
        referenceInput.maxLength = 150;
        referenceLabel.append(referenceInput);
        const detailsLabel = element("label", "account-field");
        detailsLabel.append(element("span", "", "Payment evidence or details"));
        const detailsInput = element("textarea");
        detailsInput.name = "proof_details";
        detailsInput.maxLength = 4000;
        detailsInput.rows = 3;
        detailsLabel.append(detailsInput);
        const submit = element("button", "button button-primary account-submit", "Submit for review");
        submit.type = "submit";
        evidenceForm.append(referenceLabel, detailsLabel, submit);
        evidenceForm.addEventListener("submit", async (event) => {
          event.preventDefault();
          const paymentReference = referenceInput.value.trim();
          const proofDetails = detailsInput.value.trim();
          if (!paymentReference && !proofDetails) {
            showMessage("Enter a payment reference or payment details.", true);
            return;
          }
          try {
            await api(`coins/orders/${order.id}/evidence`, "POST", {
              payment_reference: paymentReference || null,
              proof_details: proofDetails || null,
              idempotency_key: idempotencyKey(),
            });
            await Promise.all([refreshCoinData(), refreshAttendance()]);
            showMessage("Payment details submitted for review. Coins are added only after approval.");
          } catch (error) {
            showMessage(error.message, true);
          }
        });
        card.append(evidenceForm);
      }
      list.append(card);
    });
  };

  const refreshCoinData = async () => {
    const [wallet, transactionData, packagesData, methodsData, orderData] = await Promise.all([
      api("wallet"),
      api("wallet/transactions?limit=30"),
      api("coins/packages"),
      api("coins/payment-methods"),
      api("coins/orders"),
    ]);
    renderWallet(wallet, transactionData.transactions);
    renderCoinCatalog(packagesData.packages, methodsData.payment_methods);
    renderOrders(orderData.orders);
  };

  const updateProfileView = async (user) => {
    const profileData = await api("users/me");
    const profile = profileData.profile;
    currentProfile = profile;
    const mobileAuthTransition =
      window.matchMedia("(max-width: 760px)").matches &&
      authPanel.contains(document.activeElement);
    if (document.activeElement instanceof HTMLElement) {
      document.activeElement.blur();
    }
    authPanel.hidden = true;
    profilePanel.hidden = false;
    tabs.hidden = true;
    renderPlayerHeader({ ...profile, username: profile.username || user.username, email: profile.email || user.email });
    for (const field of ["display_name", "full_name", "avatar_url", "country", "bio", "free_fire_id"]) {
      forms.profile.elements[field].value = profile[field] || "";
    }
    selectProfileTab("overview");
    const scrollToAccountTop = scrollDocumentToTop;
    scrollToAccountTop();
    window.setTimeout(scrollToAccountTop, 500);
    if (mobileAuthTransition && window.visualViewport) {
      const viewport = window.visualViewport;
      let settleTimer = 0;
      const handleViewportResize = () => {
        window.clearTimeout(settleTimer);
        settleTimer = window.setTimeout(() => {
          viewport.removeEventListener("resize", handleViewportResize);
          scrollToAccountTop();
        }, 120);
      };
      viewport.addEventListener("resize", handleViewportResize);
      window.setTimeout(() => {
        viewport.removeEventListener("resize", handleViewportResize);
        window.clearTimeout(settleTimer);
        scrollToAccountTop();
      }, 1500);
    }
    const updates = await Promise.allSettled([
      refreshCoinData(),
      refreshAttendance(),
      refreshGameProfile(),
    ]);
    if (updates[1].status === "rejected") {
      attendanceStatus.textContent = updates[1].reason instanceof Error
        ? updates[1].reason.message
        : "Attendance could not be loaded.";
    }
    const failure = updates.find((result) => result.status === "rejected");
    if (failure) {
      const error = failure.reason instanceof Error ? failure.reason.message : "Some profile information could not be loaded.";
      showMessage(error, true);
    } else {
      showMessage("");
    }
  };

  const loadAccount = async () => {
    try {
      await ensureCsrf();
      if (resetToken) return;
      if (verificationToken) {
        await api("auth/email/verify", "POST", { token: verificationToken });
        verificationToken = "";
        window.history.replaceState(null, "", window.location.pathname);
        showMessage("Your email address is verified. You can now sign in.");
        return;
      }
      const response = await api("auth/me");
      await updateProfileView(response.user);
    } catch (error) {
      if (verificationToken) {
        verificationToken = "";
        window.history.replaceState(null, "", window.location.pathname);
        showMessage(error.message, true);
        return;
      }
      if (error.code === "AUTH_REQUIRED") {
        authPanel.hidden = false;
        profilePanel.hidden = true;
        setMode("login");
        return;
      }
      showMessage(error.message, true);
    }
  };

  document.querySelectorAll("[data-mode]").forEach((button) => {
    button.addEventListener("click", () => setMode(button.dataset.mode));
  });
  profileTabButtons.forEach((button, index) => {
    button.addEventListener("click", () => selectProfileTab(button.dataset.profileTab));
    button.addEventListener("keydown", (event) => {
      let nextIndex = index;
      if (event.key === "ArrowRight") nextIndex = (index + 1) % profileTabButtons.length;
      else if (event.key === "ArrowLeft") nextIndex = (index - 1 + profileTabButtons.length) % profileTabButtons.length;
      else if (event.key === "Home") nextIndex = 0;
      else if (event.key === "End") nextIndex = profileTabButtons.length - 1;
      else return;
      event.preventDefault();
      selectProfileTab(profileTabButtons[nextIndex].dataset.profileTab, true);
    });
  });
  profilePanel.querySelectorAll("[data-avatar-preset]").forEach((button) => {
    button.addEventListener("click", () => {
      avatarInput.value = button.dataset.avatarPreset;
      setAvatarImage(avatarPreview, avatarInput.value);
      profilePanel.querySelectorAll("[data-avatar-preset]").forEach((preset) => {
        const selected = preset === button;
        preset.classList.toggle("is-selected", selected);
        preset.setAttribute("aria-pressed", String(selected));
      });
    });
  });
  avatarInput.addEventListener("input", () => {
    setAvatarImage(avatarPreview, avatarInput.value);
    profilePanel.querySelectorAll("[data-avatar-preset]").forEach((button) => {
      const selected = button.dataset.avatarPreset === avatarInput.value.trim();
      button.classList.toggle("is-selected", selected);
      button.setAttribute("aria-pressed", String(selected));
    });
  });
  profilePanel.querySelector("[data-copy-free-fire-id]").addEventListener("click", async () => {
    try {
      if (!navigator.clipboard?.writeText) {
        throw new Error("Clipboard access is unavailable in this browser.");
      }
      await navigator.clipboard.writeText(freeFireIdValue.textContent);
      showMessage("Free Fire ID copied.");
    } catch (error) {
      showMessage(error.message, true);
    }
  });
  profilePanel.querySelector("[data-resend-profile-verification]").addEventListener("click", async (event) => {
    const button = event.currentTarget;
    button.disabled = true;
    try {
      await api("auth/email/resend", "POST", { email: currentProfile.email });
      showMessage("A new confirmation link was sent. Please confirm it within 48 hours.");
    } catch (error) {
      showMessage(error.message, true);
    } finally {
      button.disabled = false;
    }
  });

  profilePanel.querySelector("[data-password-reset]").addEventListener("click", async (event) => {
    const button = event.currentTarget;
    button.disabled = true;
    try {
      const result = await api("auth/password/forgot", "POST", { email: currentProfile.email });
      showMessage(result.message || "If the account email is active, a password reset link will be sent.");
    } catch (error) {
      showMessage(error.message, true);
    } finally {
      button.disabled = false;
    }
  });
  resendVerificationButton.addEventListener("click", async () => {
    resendVerificationButton.disabled = true;
    try {
      await api("auth/email/resend", "POST", {
        identifier: pendingVerificationIdentifier,
      });
      showVerificationNotice(pendingVerificationIdentifier, "A new confirmation link was sent. Please confirm your account within 48 hours.");
      showMessage("Confirmation email sent.");
    } catch (error) {
      showMessage(error.message, true);
    } finally {
      resendVerificationButton.disabled = false;
    }
  });

  document.querySelector("[data-show-forgot]").addEventListener("click", () => setMode("forgot"));
  document.querySelector("[data-show-login]").addEventListener("click", () => setMode("login"));

  try {
    const savedIdentifier = window.localStorage.getItem("neen_saved_identifier");
    if (savedIdentifier && forms.login?.elements.identifier) {
      forms.login.elements.identifier.value = savedIdentifier;
    }
  } catch {}

  forms.login.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!forms.login.reportValidity()) return;
    const identifier = String(forms.login.elements.identifier.value || "").trim();
    const password = String(forms.login.elements.password.value || "");
    const rememberMe = Boolean(forms.login.elements.remember_me?.checked);
    try {
      const result = await api("auth/login", "POST", {
        identifier,
        password,
        remember_me: rememberMe,
      });
      try {
        if (rememberMe && identifier) {
          window.localStorage.setItem("neen_saved_identifier", identifier);
        } else {
          window.localStorage.removeItem("neen_saved_identifier");
        }
      } catch {}
      csrfToken = result.csrf_token;
      window.dispatchEvent(new Event("neen:authchange"));
      await updateProfileView(result.user);
    } catch (error) {
      showMessage(error.message, true);
    }
  });

  forms.register.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!forms.register.reportValidity()) return;
    const data = Object.fromEntries(new FormData(forms.register));
    if (!data.display_name) delete data.display_name;
    try {
      const result = await api("auth/register", "POST", data);
      forms.login.elements.identifier.value = data.email;
      forms.register.reset();
      setMode("login");
      showVerificationNotice(data.email, result.verification_email_sent
        ? "A confirmation link was sent to your email. Confirm it within 48 hours."
        : "Your account was created, but the confirmation email could not be sent. Resend it below.");
      window.setTimeout(scrollAccountPageToTop, 500);
      showMessage(result.email_verification_required
        ? (result.verification_email_sent
          ? "Your account is ready. Check your email to verify your address."
          : "Your account is ready, but the verification email could not be sent. Contact the site administrator.")
        : "Your account is ready. Sign in to continue.", !result.verification_email_sent && result.email_verification_required);
    } catch (error) {
      showMessage(error.message, true);
    }
  });

  forms.forgot.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!forms.forgot.reportValidity()) return;
    try {
      const result = await api("auth/password/forgot", "POST", {
        email: forms.forgot.elements.email.value,
      });
      showMessage(result.message);
    } catch (error) {
      showMessage(error.message, true);
    }
  });

  forms.reset.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!forms.reset.reportValidity()) return;
    try {
      await api("auth/password/reset", "POST", {
        token: resetToken,
        password: forms.reset.elements.password.value,
      });
      window.dispatchEvent(new Event("neen:authchange"));
      resetToken = "";
      window.history.replaceState(null, "", window.location.pathname);
      forms.reset.reset();
      setMode("login");
      await requestCsrfToken();
      showMessage("Password updated. Sign in with your new password.");
    } catch (error) {
      showMessage(error.message, true);
    }
  });

  forms.passwordChange.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!forms.passwordChange.reportValidity()) return;
    const data = Object.fromEntries(new FormData(forms.passwordChange));
    try {
      await api("auth/password/change", "POST", {
        current_password: data.current_password,
        new_password: data.new_password,
      });
      forms.passwordChange.reset();
      showMessage("Password changed. Other signed-in devices have been signed out.");
    } catch (error) {
      showMessage(error.message, true);
    }
  });

  forms.profile.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!forms.profile.reportValidity()) return;
    const data = Object.fromEntries(new FormData(forms.profile));
    for (const field of Object.keys(data)) {
      if (data[field] === "") data[field] = null;
    }
    try {
      const result = await api("users/me", "PATCH", data);
      currentProfile = result.profile;
      renderPlayerHeader(currentProfile);
      showMessage("Profile saved.");
    } catch (error) {
      showMessage(error.message, true);
    }
  });

  document.querySelector("#coin-order-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    try {
      await api("coins/orders", "POST", {
        package_id: Number(form.elements.package_id.value),
        payment_method_id: Number(form.elements.payment_method_id.value),
        idempotency_key: idempotencyKey(),
      });
      await refreshCoinData();
      showMessage("Payment request created. No Coins are added until payment is reviewed and approved.");
    } catch (error) {
      showMessage(error.message, true);
    }
  });

  attendanceButton.addEventListener("click", async () => {
    attendanceButton.disabled = true;
    try {
      const result = await api("attendance/check-in", "POST", {});
      renderAttendance(result.attendance);
      await refreshCoinData();
      showMessage(result.created
        ? `Daily attendance claimed: +100 Earned Coins. Your balance is updated.`
        : "Today's attendance was already recorded. No duplicate Coins were added.");
    } catch (error) {
      attendanceButton.disabled = false;
      showMessage(error.message, true);
      try {
        await refreshAttendance();
      } catch (refreshError) {
        attendanceStatus.textContent = refreshError.message;
      }
    }
  });

  document.querySelector("[data-logout]").addEventListener("click", async () => {
    try {
      await api("auth/logout", "POST", {});
      window.dispatchEvent(new Event("neen:authchange"));
      await requestCsrfToken();
      authPanel.hidden = false;
      profilePanel.hidden = true;
      currentProfile = null;
      forms.login.reset();
      setMode("login");
      showMessage("You have signed out.");
    } catch (error) {
      showMessage(error.message, true);
    }
  });

  if (resetToken) {
    window.history.replaceState(null, "", window.location.pathname);
    authPanel.hidden = false;
    profilePanel.hidden = true;
    setMode("reset");
  } else {
    setMode("login");
  }
  loadAccount();
})();
