(() => {
  const board = document.querySelector("[data-match-board]");
  const startButton = document.querySelector("[data-match-start]");
  const status = document.querySelector("[data-match-status]");
  const signIn = document.querySelector("[data-match-signin]");
  const finish = document.querySelector("[data-match-finish]");
  const progress = document.querySelector("[data-match-progress]");
  const turns = document.querySelector("[data-match-turns]");
  const attempts = document.querySelector("[data-match-attempts]");
  const totalCoins = document.querySelector("[data-match-total-coins]");
  const earnedCoins = document.querySelector("[data-match-earned-coins]");
  const rewardMessage = document.querySelector("[data-match-reward]");
  const startLabel = startButton.querySelector("span");

  let csrfToken = "";
  let game = null;
  let currentSession = null;
  let pendingStartKey = "";
  let locked = true;
  let mismatchTimer = 0;
  const cards = new Map();

  const api = async (path, method = "GET", data = null) => {
    const options = {
      method,
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    };
    if (data !== null) {
      options.headers["Content-Type"] = "application/json";
      options.headers["X-CSRF-Token"] = csrfToken;
      options.body = JSON.stringify(data);
    }

    const response = await fetch(`/api/${path}`, options);
    let result;
    try {
      result = await response.json();
    } catch {
      throw new Error("The game service returned an invalid response.");
    }
    if (!response.ok || !result.success) {
      const error = new Error(result.errors?.[0]?.message || "The game request could not be completed.");
      error.code = result.errors?.[0]?.code;
      error.status = response.status;
      throw error;
    }
    return result.data;
  };

  const element = (tagName, className, text) => {
    const node = document.createElement(tagName);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  };

  const setStatus = (text, isError = false) => {
    status.textContent = text;
    status.classList.toggle("is-error", isError);
  };

  const buildBoard = () => {
    board.replaceChildren();
    cards.clear();
    for (let index = 0; index < game.cards_total; index += 1) {
      const button = element("button", "memory-card");
      button.type = "button";
      button.dataset.cardIndex = String(index);
      button.setAttribute("aria-label", `Card ${index + 1}, face down`);

      const inner = element("span", "memory-card-inner");
      inner.setAttribute("aria-hidden", "true");
      inner.append(
        element("span", "memory-card-back", "✦"),
        element("span", "memory-card-face"),
      );
      button.append(inner);
      button.addEventListener("click", () => flipCard(index));
      board.append(button);
      cards.set(index, button);
    }
    board.setAttribute("aria-busy", "false");
  };

  const showImage = (index, image, matched) => {
    const card = cards.get(index);
    if (!card || !image) return;
    const face = card.querySelector(".memory-card-face");
    const img = document.createElement("img");
    img.src = image.src;
    img.alt = image.alt;
    img.loading = "lazy";
    img.decoding = "async";
    img.addEventListener("error", () => {
      face.replaceChildren(element("span", "memory-image-fallback", "Image unavailable"));
    }, { once: true });
    face.replaceChildren(img);
    card.classList.add(matched ? "is-matched" : "is-revealed");
    card.setAttribute("aria-label", `Card ${index + 1}: ${image.alt}${matched ? ", matched" : ", revealed"}`);
  };

  const conceal = (index) => {
    const card = cards.get(index);
    if (!card) return;
    card.classList.remove("is-revealed", "is-matched");
    card.querySelector(".memory-card-face").replaceChildren();
    card.setAttribute("aria-label", `Card ${index + 1}, face down`);
  };

  const updateControls = () => {
    const isActive = currentSession && ["started", "in_progress"].includes(currentSession.status);
    cards.forEach((card, index) => {
      const isUnavailable = currentSession?.matched_cards.some((item) => item.index === index)
        || currentSession?.revealed_cards.some((item) => item.index === index);
      card.disabled = locked || !isActive || Boolean(isUnavailable);
    });
    startButton.disabled = locked || Boolean(isActive);
    startLabel.textContent = isActive ? "Game in progress" : "Start a game";
  };

  const updateWallet = (wallet) => {
    totalCoins.textContent = String(wallet.coin_balance);
    earnedCoins.textContent = String(wallet.earned_coin_balance);
  };

  const refreshWallet = async () => {
    try {
      updateWallet(await api("wallet"));
    } catch (error) {
      totalCoins.textContent = "Unavailable";
      earnedCoins.textContent = "Unavailable";
      totalCoins.title = error.message;
      earnedCoins.title = error.message;
    }
  };

  const renderSession = (session, reward = null) => {
    window.clearTimeout(mismatchTimer);
    currentSession = session;
    cards.forEach((_, index) => conceal(index));
    session.matched_cards.forEach((item) => showImage(item.index, item.image, true));
    session.revealed_cards.forEach((item) => showImage(item.index, item.image, false));
    progress.textContent = `${session.matched_pairs} / ${session.pairs_total}`;
    turns.textContent = String(session.move_count);

    const complete = session.status === "completed";
    finish.hidden = !complete;
    rewardMessage.hidden = !reward;
    if (reward) {
      updateWallet({
        coin_balance: reward.coin_balance,
        earned_coin_balance: reward.earned_coin_balance,
      });
      rewardMessage.textContent = `+${reward.amount} Earned Coins credited. Earned balance: ${reward.earned_coin_balance}; total balance: ${reward.coin_balance}.`;
    }
    if (complete) {
      setStatus(reward
        ? `You won: ${reward.amount} Earned Coins were credited by the server.`
        : "The server confirmed that every pair has been found. Loading this completed session does not grant another reward.");
    } else if (session.status === "failed") {
      setStatus("This game session has ended. Start another attempt if you have one available.");
    } else if (session.mismatch_wait_ms > 0) {
      setStatus("Two cards are different. Remember them while they turn back over.");
      const revealed = session.revealed_cards.map((item) => item.index);
      const hideAfterDelay = async () => {
        try {
          const data = await api(
            `games/memory-match/sessions/${session.id}/hide-mismatch`,
            "POST",
            {},
          );
          renderSession(data.session, data.reward || null);
          setStatus("Choose another card when you're ready.");
        } catch (error) {
          if (error.code === "PAIR_REVEAL_PENDING") {
            mismatchTimer = window.setTimeout(hideAfterDelay, 100);
            return;
          }
          revealed.forEach(conceal);
          currentSession.revealed_cards = [];
          currentSession.mismatch_wait_ms = 0;
          locked = false;
          updateControls();
          setStatus(error.message, true);
        }
      };
      mismatchTimer = window.setTimeout(hideAfterDelay, session.mismatch_wait_ms);
    } else {
      setStatus(session.matched_pairs > 0 ? "Great match. Find the remaining pairs." : "Choose any card to reveal its image.");
    }
    locked = session.mismatch_wait_ms > 0;
    updateControls();
  };

  const refreshSession = async () => {
    const data = await api("games/memory-match/sessions/current");
    if (data.session) {
      renderSession(data.session);
      if (data.session.status === "completed") await refreshWallet();
    } else {
      currentSession = null;
      finish.hidden = true;
      progress.textContent = `0 / ${game.pairs_total}`;
      turns.textContent = "0";
      updateControls();
    }
  };

  const flipCard = async (index) => {
    if (locked || !currentSession) return;
    const card = cards.get(index);
    if (!card || card.disabled) return;
    locked = true;
    updateControls();
    try {
      const data = await api(
        `games/memory-match/sessions/${currentSession.id}/flip`,
        "POST",
        { card_index: index },
      );
      renderSession(data.session, data.reward || null);
    } catch (error) {
      if (error.status === 401) {
        signIn.hidden = false;
      }
      if (error.code === "GAME_SESSION_EXPIRED") {
        currentSession.status = "failed";
        setStatus(error.message, true);
      } else {
        setStatus(error.message, true);
      }
      try {
        await refreshSession();
      } catch {
        locked = false;
        updateControls();
      }
    }
  };

  startButton.addEventListener("click", async () => {
    if (locked) return;
    locked = true;
    updateControls();
    startButton.setAttribute("aria-busy", "true");
    if (!pendingStartKey) pendingStartKey = crypto.randomUUID();
    try {
      const data = await api("games/memory-match/sessions", "POST", { idempotency_key: pendingStartKey });
      pendingStartKey = "";
      attempts.textContent = String(data.attempts_remaining_today);
      signIn.hidden = true;
      finish.hidden = true;
      renderSession(data.session);
      if (data.attempts_remaining_today === 0 && data.session.status !== "completed") {
        setStatus("This is your last free Memory Match start for today.");
      }
    } catch (error) {
      if (error.status === 401) {
        signIn.hidden = false;
        setStatus("Sign in or create an account before starting a game.", true);
      } else {
        setStatus(error.message, true);
      }
      locked = false;
      updateControls();
    } finally {
      startButton.removeAttribute("aria-busy");
    }
  });

  const initialize = async () => {
    try {
      const [catalog, csrf] = await Promise.all([
        api("games/memory-match"),
        api("auth/csrf"),
      ]);
      game = catalog.game;
      csrfToken = csrf.csrf_token;
      buildBoard();
      progress.textContent = `0 / ${game.pairs_total}`;
      attempts.textContent = String(game.free_attempts_per_day);
      const session = await api("games/memory-match/sessions/current");
      attempts.textContent = String(session.attempts_remaining_today);
      await refreshWallet();
      if (session.session) {
        renderSession(session.session);
        setStatus(session.session.status === "completed"
          ? "This completed server-side game is saved. Reloading it does not grant another reward."
          : "Resumed your active server-side game session.");
      } else {
        currentSession = null;
        locked = false;
        updateControls();
        setStatus("Sign in to begin. Your game progress is stored on the server.");
      }
    } catch (error) {
      if (error.status === 401) {
        signIn.hidden = false;
        locked = false;
        updateControls();
        setStatus("Sign in or create an account to play.");
        return;
      }
      locked = false;
      startButton.disabled = true;
      setStatus(error.message, true);
    }
  };

  initialize();
})();
