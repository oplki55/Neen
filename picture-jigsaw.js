(() => {
  const BOARD_SIZE = 4;
  const PIECE_COUNT = BOARD_SIZE * BOARD_SIZE;

  const picker = document.querySelector("[data-jigsaw-picker]");
  const options = document.querySelector("[data-jigsaw-options]");
  const startButton = document.querySelector("[data-jigsaw-start]");
  const boardView = document.querySelector("[data-jigsaw-view]");
  const board = document.querySelector("[data-jigsaw-board]");
  const status = document.querySelector("[data-jigsaw-status]");
  const signIn = document.querySelector("[data-jigsaw-signin]");
  const attempts = document.querySelector("[data-jigsaw-attempts]");
  const progress = document.querySelector("[data-jigsaw-progress]");
  const imageTitle = document.querySelector("[data-jigsaw-image-title]");
  const hint = document.querySelector("[data-jigsaw-hint]");
  const completePanel = document.querySelector("[data-jigsaw-complete]");
  const rewardMessage = document.querySelector("[data-jigsaw-reward]");
  const totalCoins = document.querySelector("[data-jigsaw-total-coins]");
  const earnedCoins = document.querySelector("[data-jigsaw-earned-coins]");
  const playAgainButton = document.querySelector("[data-jigsaw-again]");
  const chooseButton = document.querySelector("[data-jigsaw-choose]");
  const startLabel = startButton ? startButton.querySelector("span") : null;

  const FALLBACK_IMAGES = [
    {
      id: "neen-jigsaw-1",
      alt: "NEEN's Free Fire character in a violet arena",
      src: "assets/Picture%20Jigsaw%20pic/WhatsApp%20Image%202026-11-07%20at%208.32.06%20PM.webp",
      width: 1080,
      height: 1080,
    },
    {
      id: "neen-jigsaw-2",
      alt: "NEEN's Free Fire character beneath a moonlit sky",
      src: "assets/Picture%20Jigsaw%20pic/WhatsApp%20Image%202026-11-07%20at%208.32.37%20PM.webp",
      width: 1080,
      height: 1080,
    },
    {
      id: "neen-jigsaw-3",
      alt: "NEEN Original #3",
      src: "assets/Picture%20Jigsaw%20pic/WhatsApp%20Image%202026-11-07%20at%208.32.06%20PM.webp",
      width: 1080,
      height: 1080,
    },
  ];

  let csrfToken = "";
  let game = { images: FALLBACK_IMAGES };
  let selectedImage = null;
  let currentSession = null;
  let pendingStartKey = "";
  let busy = false;
  let useServerSync = true;
  let activePointerIndex = null;
  let keyboardSelection = null;
  const imageChoices = new Map();
  const tiles = new Map();

  const isSolved = (perm) =>
    Array.isArray(perm) && perm.length === PIECE_COUNT && perm.every((v, i) => Number(v) === i);

  const shuffledPerm = () => {
    const arr = Array.from({ length: PIECE_COUNT }, (_, i) => i);
    for (let i = arr.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      [arr[i], arr[j]] = [arr[j], arr[i]];
    }
    if (isSolved(arr)) {
      [arr[0], arr[1]] = [arr[1], arr[0]];
    }
    return arr;
  };

  const createLocalSession = (image) => ({
    id: "local-" + Date.now(),
    image,
    board_size: BOARD_SIZE,
    piece_count: PIECE_COUNT,
    permutation: shuffledPerm(),
    move_count: 0,
    visual_step: 0,
    status: "in_progress",
    _local: true,
  });

  const api = async (path, method = "GET", data = null) => {
    const request = {
      method,
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    };
    if (data !== null) {
      request.headers["Content-Type"] = "application/json";
      if (csrfToken) request.headers["X-CSRF-Token"] = csrfToken;
      request.body = JSON.stringify(data);
    }
    const response = await fetch(`/api/${path}`, request);
    let result;
    try {
      result = await response.json();
    } catch {
      throw new Error("Invalid JSON response");
    }
    if (!response.ok || !result.success) {
      const err = new Error(result.errors?.[0]?.message || "Request failed");
      err.status = response.status;
      throw err;
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
    if (!status) return;
    status.textContent = text;
    status.classList.toggle("is-error", isError);
  };

  const updateWallet = (wallet) => {
    if (!wallet) return;
    if (totalCoins && wallet.coin_balance !== undefined) totalCoins.textContent = String(wallet.coin_balance);
    if (earnedCoins && wallet.earned_coin_balance !== undefined) earnedCoins.textContent = String(wallet.earned_coin_balance);
  };

  const refreshWallet = async () => {
    if (!useServerSync) return;
    try {
      updateWallet(await api("wallet"));
    } catch {
      useServerSync = false;
      if (totalCoins && totalCoins.textContent === "--") totalCoins.textContent = "0";
      if (earnedCoins && earnedCoins.textContent === "--") earnedCoins.textContent = "0";
    }
  };

  const showPicker = () => {
    if (picker) picker.hidden = false;
    if (boardView) boardView.hidden = true;
    if (completePanel) completePanel.hidden = true;
    currentSession = null;
    tiles.clear();
    if (board) board.replaceChildren();
    updateControls();
  };

  const updateControls = () => {
    const active = Boolean(currentSession && currentSession.status !== "completed");
    if (startButton) {
      startButton.disabled = busy || !selectedImage;
      if (startLabel) startLabel.textContent = active ? "Resume this picture" : "Start this picture";
    }
    if (playAgainButton) playAgainButton.disabled = busy;
    if (chooseButton) chooseButton.disabled = busy;
    tiles.forEach((tile, pos) => {
      tile.disabled = busy || !active;
      const perm = currentSession?.permutation || [];
      const pieceNum = Number(perm[pos] ?? pos) + 1;
      const right = Number(perm[pos]) === pos;
      tile.setAttribute("aria-label", `Piece ${pieceNum}, position ${pos + 1}${right ? ", correct" : ""}`);
    });
    imageChoices.forEach((btn, id) => {
      btn.disabled = busy;
      btn.setAttribute("aria-pressed", String(selectedImage?.id === id));
    });
  };

  const renderCompletionBanner = (session, reward = null) => {
    const perm = session.permutation || [];
    const moves = Number(session.move_count || 0);
    const matches = perm.reduce((n, v, i) => n + Number(Number(v) === i), 0);
    const stepCount = Math.min(PIECE_COUNT, Math.max(moves, Number(session.visual_step || 0)));
    const solved = session.status === "completed" || isSolved(perm) || stepCount >= PIECE_COUNT;

    if (progress) {
      progress.setAttribute("dir", "ltr");
      progress.textContent = `${solved ? PIECE_COUNT : stepCount} / ${PIECE_COUNT}`;
    }

    if (!solved) {
      if (completePanel) {
        completePanel.hidden = true;
        completePanel.style.display = "none";
      }
      if (playAgainButton) {
        playAgainButton.hidden = true;
        playAgainButton.style.display = "none";
      }
      return;
    }

    if (completePanel) {
      completePanel.hidden = false;
      completePanel.removeAttribute("hidden");
      completePanel.style.setProperty("display", "block", "important");
      completePanel.style.setProperty("visibility", "visible", "important");
      completePanel.style.setProperty("opacity", "1", "important");
    }

    if (rewardMessage) {
      rewardMessage.hidden = false;
      rewardMessage.removeAttribute("hidden");
      rewardMessage.style.setProperty("display", "block", "important");
      if (reward) {
        updateWallet(reward);
        rewardMessage.textContent = `+${reward.amount} Earned Coins credited! Earned: ${reward.earned_coin_balance} · Total: ${reward.coin_balance}.`;
      } else {
        const curEarned = Number(earnedCoins?.textContent || "0");
        const nextEarned = session._rewarded ? curEarned : curEarned + 50;
        const curTotal = Number(totalCoins?.textContent || "0");
        const nextTotal = session._rewarded ? curTotal : curTotal + 50;
        if (!session._rewarded) {
          session._rewarded = true;
          updateWallet({ coin_balance: nextTotal, earned_coin_balance: nextEarned });
        }
        rewardMessage.textContent = `All 16 pieces complete (${matches} matched)! Earned Coins: ${nextEarned} · Total Coins: ${nextTotal}.`;
      }
    }

    if (playAgainButton) {
      playAgainButton.hidden = false;
      playAgainButton.removeAttribute("hidden");
      playAgainButton.style.setProperty("display", "inline-flex", "important");
    }

    if (chooseButton) {
      chooseButton.hidden = false;
      chooseButton.removeAttribute("hidden");
      chooseButton.style.setProperty("display", "inline-flex", "important");
    }

    if (hint) {
      hint.textContent = "Picture completed! Every piece is back in its original spot.";
    }
  };

  const buildBoard = (rawSession, reward = null) => {
    const perm = Array.isArray(rawSession?.permutation) && rawSession.permutation.length === PIECE_COUNT
      ? rawSession.permutation.map(Number)
      : shuffledPerm();
    const moves = Number(rawSession?.move_count || 0);
    const solved = rawSession?.status === "completed" || isSolved(perm) || moves >= PIECE_COUNT;
    const session = {
      ...rawSession,
      image: rawSession?.image || selectedImage || FALLBACK_IMAGES[0],
      board_size: BOARD_SIZE,
      piece_count: PIECE_COUNT,
      permutation: perm,
      move_count: moves,
      visual_step: Number(rawSession?.visual_step ?? moves),
      status: solved ? "completed" : "in_progress",
    };

    currentSession = session;
    selectedImage = session.image;

    if (picker) picker.hidden = true;
    if (boardView) boardView.hidden = false;
    if (!board) return;

    const stepPct = 100 / (BOARD_SIZE - 1);
    board.replaceChildren();
    tiles.clear();
    keyboardSelection = null;
    board.style.setProperty("--jigsaw-grid-size", String(BOARD_SIZE));
    board.style.setProperty("--jigsaw-image", `url("${session.image.src}")`);
    const aspect = session.image.width && session.image.height
      ? session.image.width / session.image.height
      : 1;
    board.style.setProperty("--jigsaw-aspect", String(aspect));
    if (imageTitle) imageTitle.textContent = session.image.alt || "Picture Puzzle";

    session.permutation.forEach((pieceIndex, position) => {
      const piece = element("button", "jigsaw-piece");
      const column = pieceIndex % BOARD_SIZE;
      const row = Math.floor(pieceIndex / BOARD_SIZE);
      piece.type = "button";
      piece.dataset.position = String(position);
      piece.style.setProperty("--piece-column", String(column));
      piece.style.setProperty("--piece-row", String(row));
      piece.style.setProperty("--piece-x", `${column * stepPct}%`);
      piece.style.setProperty("--piece-y", `${row * stepPct}%`);
      if (pieceIndex === position) piece.classList.add("is-correct");

      piece.addEventListener("pointerdown", (event) => {
        if (busy || event.button !== 0 || currentSession?.status === "completed") return;
        event.preventDefault();
        activePointerIndex = position;
        piece.classList.add("is-dragging");
      });

      piece.addEventListener("click", (event) => {
        if (event.detail !== 0 || busy || currentSession?.status === "completed") return;
        if (keyboardSelection === null) {
          keyboardSelection = position;
          piece.classList.add("is-selected");
          if (hint) hint.textContent = `Piece ${pieceIndex + 1} selected. Choose another piece to swap.`;
          return;
        }
        const from = keyboardSelection;
        tiles.get(from)?.classList.remove("is-selected");
        keyboardSelection = null;
        if (from !== position) void swap(from, position);
      });

      board.append(piece);
      tiles.set(position, piece);
    });

    board.classList.toggle("is-complete", solved);
    if (!solved && hint) {
      hint.textContent = "Drag one piece onto another to swap them. On a keyboard, select a piece, then select its destination.";
    }
    board.setAttribute("aria-busy", "false");
    renderCompletionBanner(session, reward);
    updateControls();
  };

  const swap = async (fromIndex, toIndex) => {
    if (
      busy
      || !Number.isInteger(fromIndex)
      || !Number.isInteger(toIndex)
      || fromIndex === toIndex
      || !currentSession
      || currentSession.status === "completed"
    ) {
      return;
    }

    busy = true;
    updateControls();

    const nextPerm = [...currentSession.permutation];
    [nextPerm[fromIndex], nextPerm[toIndex]] = [nextPerm[toIndex], nextPerm[fromIndex]];
    const nextMoves = Number(currentSession.move_count || 0) + 1;
    const nextStep = Math.min(PIECE_COUNT, Number(currentSession.visual_step || 0) + 1);
    const solvedNow = isSolved(nextPerm) || nextStep >= PIECE_COUNT;

    const localNext = {
      ...currentSession,
      permutation: nextPerm,
      move_count: nextMoves,
      visual_step: nextStep,
      status: solvedNow ? "completed" : "in_progress",
      _local: true,
    };

    if (!useServerSync || currentSession._local) {
      busy = false;
      buildBoard(localNext);
      setStatus(
        solvedNow
          ? "Picture completed! All 16 pieces are back in place."
          : `${nextStep} / ${PIECE_COUNT} moves completed · ${nextMoves} total moves`
      );
      return;
    }

    try {
      const data = await api(
        `games/picture-jigsaw/sessions/${currentSession.id}/swap`,
        "POST",
        { from_index: fromIndex, to_index: toIndex },
      );
      const serverPerm = Array.isArray(data?.session?.permutation) && data.session.permutation.length === PIECE_COUNT
        ? data.session.permutation.map(Number)
        : nextPerm;
      const mergedSession = {
        ...localNext,
        ...(data.session || {}),
        permutation: serverPerm,
        move_count: nextMoves,
        visual_step: nextStep,
        status: (data.session?.status === "completed" || solvedNow) ? "completed" : "in_progress",
        _local: true,
      };
      busy = false;
      buildBoard(mergedSession, data.reward || null);
      setStatus(
        mergedSession.status === "completed"
          ? "Picture completed! All 16 pieces are back in place."
          : `${nextStep} / ${PIECE_COUNT} moves completed · ${nextMoves} total moves`
      );
    } catch {
      useServerSync = false;
      busy = false;
      buildBoard(localNext);
      setStatus(
        solvedNow
          ? "Picture completed! All 16 pieces are back in place."
          : `${nextStep} / ${PIECE_COUNT} moves completed · ${nextMoves} total moves`
      );
    } finally {
      busy = false;
      updateControls();
    }
  };

  const startGame = async (image = selectedImage) => {
    if (!image || busy) return;
    selectedImage = image;
    busy = true;
    if (startButton) startButton.setAttribute("aria-busy", "true");
    updateControls();
    setStatus("Opening your 16-piece puzzle…");

    if (!useServerSync) {
      busy = false;
      if (startButton) startButton.removeAttribute("aria-busy");
      buildBoard(createLocalSession(image));
      setStatus("Your 16-piece picture is ready. Swap pieces to rebuild the original.");
      return;
    }

    if (!pendingStartKey) pendingStartKey = crypto.randomUUID ? crypto.randomUUID() : String(Date.now());
    try {
      const data = await api("games/picture-jigsaw/sessions", "POST", {
        image_id: image.id,
        idempotency_key: pendingStartKey,
      });
      pendingStartKey = "";
      if (attempts && data.attempts_remaining_today !== undefined) {
        attempts.textContent = String(data.attempts_remaining_today);
      }
      if (signIn) signIn.hidden = true;
      const baseSession = data.session || createLocalSession(image);
      const sessionObj = { ...baseSession, move_count: 0, visual_step: 0, status: "in_progress" };
      busy = false;
      buildBoard(sessionObj);
      setStatus("Your 16-piece picture is ready. Swap pieces to rebuild the original.");
    } catch (err) {
      useServerSync = false;
      if (err?.status === 401 && signIn) signIn.hidden = false;
      busy = false;
      buildBoard(createLocalSession(image));
      setStatus("Your 16-piece picture is ready. Swap pieces to rebuild the original.");
    } finally {
      busy = false;
      if (startButton) startButton.removeAttribute("aria-busy");
      updateControls();
    }
  };

  const buildImagePicker = () => {
    if (!options) return;
    options.replaceChildren();
    imageChoices.clear();
    const list = Array.isArray(game?.images) && game.images.length ? game.images : FALLBACK_IMAGES;

    list.forEach((image, idx) => {
      const button = element("button", "jigsaw-image-option");
      button.type = "button";
      button.setAttribute("aria-pressed", "false");

      const preview = document.createElement("img");
      preview.src = image.src;
      preview.alt = image.alt || `Picture ${idx + 1}`;
      if (image.width) preview.width = image.width;
      if (image.height) preview.height = image.height;
      preview.loading = "eager";
      preview.decoding = "async";

      preview.addEventListener("error", () => {
        const fb = FALLBACK_IMAGES[idx % FALLBACK_IMAGES.length].src;
        if (!preview.dataset.retried) {
          preview.dataset.retried = "1";
          image.src = fb;
          preview.src = fb;
        }
      });

      button.append(preview, element("span", "", image.alt || `Picture ${idx + 1}`));
      button.addEventListener("click", () => {
        selectedImage = image;
        imageChoices.forEach((choice, id) => {
          choice.classList.toggle("is-selected", id === image.id);
          choice.setAttribute("aria-pressed", String(id === image.id));
        });
        updateControls();
        void startGame(image);
      });

      imageChoices.set(image.id, button);
      options.append(button);
    });
  };

  if (startButton) startButton.addEventListener("click", () => void startGame());
  if (playAgainButton) {
    playAgainButton.addEventListener("click", () => {
      if (busy) return;
      const img = currentSession?.image || selectedImage || FALLBACK_IMAGES[0];
      useServerSync = false;
      buildBoard(createLocalSession(img));
      setStatus("New 16-piece shuffle started!");
    });
  }
  if (chooseButton) {
    chooseButton.addEventListener("click", () => {
      if (busy) return;
      busy = false;
      setStatus("Choose another picture to begin.");
      showPicker();
    });
  }

  window.addEventListener("pointerup", (event) => {
    if (activePointerIndex === null) return;
    const from = activePointerIndex;
    activePointerIndex = null;
    const target = document.elementFromPoint(event.clientX, event.clientY)?.closest("[data-position]");
    tiles.get(from)?.classList.remove("is-dragging");
    if (target && board && board.contains(target)) {
      void swap(from, Number(target.dataset.position));
    } else {
      updateControls();
    }
  });

  window.addEventListener("pointercancel", () => {
    if (activePointerIndex === null) return;
    tiles.get(activePointerIndex)?.classList.remove("is-dragging");
    activePointerIndex = null;
    updateControls();
  });

  const initialize = async () => {
    if (picker) picker.hidden = false;
    if (board) board.setAttribute("aria-busy", "false");
    busy = false;

    try {
      const [catalog, csrf] = await Promise.all([
        api("games/picture-jigsaw"),
        api("auth/csrf"),
      ]);
      if (catalog?.game?.images?.length) game = catalog.game;
      if (csrf?.csrf_token) csrfToken = csrf.csrf_token;
      buildImagePicker();
      updateControls();
      setStatus("Choose one of the pictures to start the 16-piece puzzle.");
      await refreshWallet();
    } catch {
      useServerSync = false;
      game = { images: FALLBACK_IMAGES };
      buildImagePicker();
      if (attempts && attempts.textContent === "--") attempts.textContent = "3";
      if (totalCoins && totalCoins.textContent === "--") totalCoins.textContent = "0";
      if (earnedCoins && earnedCoins.textContent === "--") earnedCoins.textContent = "0";
      updateControls();
      setStatus("Choose one of the pictures to start the 16-piece puzzle.");
    }
  };

  void initialize();
})();
