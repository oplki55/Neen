(() => {
  const status = document.querySelector("[data-vault-status]");
  const picker = document.querySelector("[data-vault-icons]");
  const selectedNode = document.querySelector("[data-vault-selected]");
  const board = document.querySelector("[data-vault-guesses]");
  const attempts = document.querySelector("[data-vault-attempts]");
  const rewardNode = document.querySelector("[data-vault-reward]");
  const submitButton = document.querySelector("[data-vault-submit]");
  const startButton = document.querySelector("[data-vault-start]");
  if ([status, picker, selectedNode, board, attempts, rewardNode, submitButton, startButton]
    .some((node) => !(node instanceof HTMLElement))) return;

  let csrfToken = "";
  let icons = [];
  let selected = [];
  let session = null;
  let requestPending = false;

  const api = async (path, method = "GET", data = null) => {
    const headers = { Accept: "application/json" };
    const options = { method, credentials: "same-origin", cache: "no-store", headers };
    if (data !== null) {
      if (!csrfToken) csrfToken = (await api("/api/auth/csrf")).csrf_token;
      headers["Content-Type"] = "application/json";
      headers["X-CSRF-Token"] = csrfToken;
      options.body = JSON.stringify(data);
    }
    const response = await fetch(path, options);
    let result;
    try { result = await response.json(); } catch { throw new Error("The game service returned an unreadable response."); }
    if (!response.ok || result.success !== true) {
      const error = new Error(result.errors?.[0]?.message || "The game request could not be completed.");
      error.code = result.errors?.[0]?.code;
      throw error;
    }
    return result.data;
  };

  const setStatus = (text, error = false) => {
    status.textContent = text;
    status.classList.toggle("is-error", error);
  };

  const iconById = (id) => icons.find((icon) => icon.id === id);

  const renderIcons = () => {
    picker.replaceChildren();
    for (const icon of icons) {
      const button = document.createElement("button");
      button.type = "button";
      button.className = `vault-icon vault-icon-button${selected.includes(icon.id) ? " is-selected" : ""}`;
      button.disabled = requestPending || (selected.length >= 4 && !selected.includes(icon.id));
      button.setAttribute("aria-label", icon.id.replaceAll("-", " "));
      button.setAttribute("aria-pressed", String(selected.includes(icon.id)));
      const image = document.createElement("img");
      image.src = icon.src;
      image.alt = "";
      button.append(image);
      button.addEventListener("click", () => {
        selected = selected.includes(icon.id)
          ? selected.filter((item) => item !== icon.id)
          : [...selected, icon.id];
        renderIcons();
        renderSelected();
      });
      picker.append(button);
    }
  };

  const renderSelected = () => {
    const names = selected.map((id) => iconById(id)?.id.replaceAll("-", " ") || id);
    selectedNode.textContent = names.length ? names.join(" · ") : "Select four different icons to make a guess.";
    submitButton.disabled = selected.length !== 4 || requestPending || !session
      || !["started", "in_progress"].includes(session.status);
  };

  const renderSession = (nextSession, remaining = null) => {
    session = nextSession;
    attempts.textContent = String(session?.attempts_remaining ?? 0);
    if (remaining !== null) attempts.dataset.startsRemaining = String(remaining);
    board.replaceChildren();
    for (const guess of session?.guesses ?? []) {
      const row = document.createElement("div");
      row.className = "vault-guess";
      for (const id of guess.icons) {
        const icon = iconById(id);
        const tile = document.createElement("span");
        tile.className = "vault-icon";
        const image = document.createElement("img");
        image.src = icon?.src || "";
        image.alt = icon?.id || "Icon";
        tile.append(image);
        row.append(tile);
      }
      const feedback = document.createElement("span");
      feedback.className = "vault-feedback";
      feedback.textContent = `${guess.exact_matches} exact · ${guess.wrong_position_matches} misplaced`;
      row.append(feedback);
      board.append(row);
    }
    selected = [];
    renderIcons();
    renderSelected();
    const canPlay = session && ["started", "in_progress"].includes(session.status);
    startButton.hidden = Boolean(canPlay);
    submitButton.hidden = !canPlay;
    if (session?.status === "completed") setStatus("Vault cracked! Your server-verified Earned Coins reward has been added.");
    else if (session?.status === "failed") setStatus("This vault is locked. Start another attempt when you’re ready.");
    else if (session) setStatus("Choose four distinct icons, then use the match feedback to solve the code.");
  };

  const load = async () => {
    try {
      const catalog = await api("/api/games/vault-cracker");
      icons = catalog.game.icons;
      rewardNode.textContent = new Intl.NumberFormat().format(catalog.game.reward_amount_coins);
      renderIcons();
      try {
        const current = await api("/api/games/vault-cracker/sessions/current");
        renderSession(current.session, current.attempts_remaining_today);
        attempts.title = `Daily starts remaining: ${current.attempts_remaining_today}`;
      } catch (error) {
        if (error.code === "AUTH_REQUIRED") {
          renderSession(null);
          startButton.hidden = true;
          setStatus("Sign in to play and earn rewards. Your daily attempts are saved to your account.", true);
        } else throw error;
      }
    } catch (error) {
      setStatus(error instanceof Error ? error.message : "The vault game could not be loaded.", true);
    }
  };

  startButton.addEventListener("click", async () => {
    if (requestPending) return;
    requestPending = true;
    startButton.disabled = true;
    try {
      const result = await api("/api/games/vault-cracker/sessions", "POST", {
        idempotency_key: crypto.randomUUID(),
      });
      renderSession(result.session, result.attempts_remaining_today);
      setStatus("Choose four distinct icons, then use the match feedback to solve the code.");
    } catch (error) {
      setStatus(error instanceof Error ? error.message : "A new vault could not be started.", true);
    } finally {
      requestPending = false;
      startButton.disabled = false;
      renderIcons();
      renderSelected();
    }
  });

  submitButton.addEventListener("click", async () => {
    if (requestPending || selected.length !== 4 || !session) return;
    requestPending = true;
    submitButton.disabled = true;
    try {
      const result = await api(`/api/games/vault-cracker/sessions/${session.id}/guess`, "POST", { guess: selected });
      renderSession(result.session, result.attempts_remaining_today);
      if (result.reward) {
        setStatus(`Vault cracked! +${new Intl.NumberFormat().format(result.reward.amount)} Earned Coins added to your wallet.`);
      }
    } catch (error) {
      setStatus(error instanceof Error ? error.message : "Your guess could not be checked.", true);
    } finally {
      requestPending = false;
      renderIcons();
      renderSelected();
    }
  });

  load();
})();
