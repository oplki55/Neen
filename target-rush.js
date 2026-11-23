(() => {
  const status = document.querySelector("[data-rush-status]");
  const attempts = document.querySelector("[data-rush-attempts]");
  const goal = document.querySelector("[data-rush-goal]");
  const rewardNode = document.querySelector("[data-rush-reward]");
  const startButton = document.querySelector("[data-rush-start]");
  const targetButton = document.querySelector("[data-rush-target]");
  const gameScreen = document.querySelector('[data-rush-screen="game"]');
  const goalProgress = document.querySelector("[data-rush-goal-progress]");
  const progressLabel = document.querySelector("[data-rush-progress-label]");
  const progressTrack = document.querySelector("[data-rush-progress-track]");
  const progressFill = document.querySelector("[data-rush-progress-fill]");
  const muteButton = document.querySelector("[data-rush-mute]");
  const screens = [...document.querySelectorAll("[data-rush-screen]")];
  if ([status, attempts, goal, rewardNode, startButton, targetButton].some((node) => !(node instanceof HTMLElement))) return;
  const previewMode = new URLSearchParams(window.location.search).get("preview") === "1";
  const previewLink = document.querySelector(".rush-preview-link");
  if (previewLink instanceof HTMLAnchorElement) previewLink.hidden = previewMode;
  const previewDurationMs = 30_000;
  const previewTargetIntervalMs = 1_100;

  const positions = {
    "upper-left": [46, 31],
    "upper-right": [62, 35],
    "middle-left": [52, 54],
    "middle-right": [71, 57],
    "lower-left": [43, 72],
    "lower-right": [87, 58],
  };
  let csrfToken = "";
  let session = null;
  let rewardAmount = 0;
  let attemptsRemaining = 0;
  let serverElapsedAnchorMs = 0;
  let serverElapsedAnchorPerfMs = 0;
  let targetTimer = 0;
  let clockTimer = 0;
  let pending = false;
  let finishing = false;
  const lockedTokens = new Set();
  let muted = false;
  let audioContext = null;
  let audioWarningShown = false;
  let lastPointerActivationAt = -Infinity;
  let previewState = null;
  let previewTimer = 0;
  let previewFallbackAvailable = false;

  const api = async (path, method = "GET", data = null) => {
    const headers = { Accept: "application/json" };
    const options = { method, credentials: "same-origin", cache: "no-store", headers };
    if (data !== null) {
      if (!csrfToken) csrfToken = (await api("/api/auth/csrf")).csrf_token;
      headers["Content-Type"] = "application/json";
      headers["X-CSRF-Token"] = csrfToken;
      options.body = JSON.stringify(data);
    }
    const requestStartedAt = performance.now();
    const response = await fetch(path, options);
    let result;
    try {
      result = await response.json();
    } catch {
      throw new Error("The game service returned an unreadable response.");
    }
    if (!response.ok || result.success !== true) {
      const error = new Error(result.errors?.[0]?.message || "The game request could not be completed.");
      error.code = result.errors?.[0]?.code;
      throw error;
    }
    const current = result.data?.session;
    if (Number.isFinite(current?.server_elapsed_ms)) {
      const receivedAt = performance.now();
      serverElapsedAnchorMs = current.server_elapsed_ms + ((receivedAt - requestStartedAt) / 2);
      serverElapsedAnchorPerfMs = receivedAt;
    }
    return result.data;
  };

  const setStatus = (message, isError = false) => {
    status.textContent = message;
    status.classList.toggle("is-error", isError);
  };

  const showScreen = (name) => {
    for (const screen of screens) {
      const visible = screen.dataset.rushScreen === name;
      screen.hidden = !visible;
      screen.classList.toggle("is-visible", visible);
    }
  };

  const formatSeconds = (milliseconds) => {
    const seconds = Math.max(0, Math.ceil(milliseconds / 1000));
    return `00:${String(seconds).padStart(2, "0")}`;
  };

  const updateGoalProgress = (score) => {
    const threshold = Math.max(1, Number(goal.textContent.replaceAll(",", "")) || 200);
    const value = Math.max(0, Math.floor(score));
    const boundedValue = Math.min(value, threshold);
    if (progressLabel instanceof HTMLElement) {
      progressLabel.textContent = `${new Intl.NumberFormat().format(value)} / ${new Intl.NumberFormat().format(threshold)}`;
    }
    if (progressTrack instanceof HTMLElement) {
      progressTrack.setAttribute("aria-valuemax", String(threshold));
      progressTrack.setAttribute("aria-valuenow", String(boundedValue));
    }
    if (progressFill instanceof HTMLElement) {
      progressFill.style.setProperty("--rush-progress", `${(boundedValue / threshold) * 100}%`);
    }
    if (goalProgress instanceof HTMLElement) {
      goalProgress.classList.toggle("is-complete", value >= threshold);
    }
  };

  const saveMutedPreference = () => {
    try {
      window.localStorage.setItem("neen-target-rush-muted", muted ? "true" : "false");
    } catch (error) {
      console.warn("Target Rush could not save the sound preference.", error);
    }
  };

  const updateMuteButton = () => {
    if (!(muteButton instanceof HTMLButtonElement)) return;
    muteButton.textContent = muted ? "SOUND OFF" : "SOUND ON";
    muteButton.setAttribute("aria-label", muted ? "Unmute sound" : "Mute sound");
    muteButton.setAttribute("aria-pressed", String(muted));
  };

  const initializeAudio = () => {
    if (!audioContext) {
      const AudioContextConstructor = window.AudioContext || window.webkitAudioContext;
      if (!AudioContextConstructor) {
        if (!audioWarningShown) {
          console.warn("Target Rush sound effects are not supported by this browser.");
          audioWarningShown = true;
        }
        return null;
      }
      audioContext = new AudioContextConstructor();
    }
    if (audioContext.state === "suspended") {
      audioContext.resume().catch((error) => {
        console.warn("Target Rush could not resume its audio context.", error);
      });
    }
    return audioContext;
  };

  const playTone = (context, frequency, endFrequency, duration, waveform, delay = 0, volume = 0.075) => {
    const oscillator = context.createOscillator();
    const gain = context.createGain();
    const startAt = context.currentTime + delay;
    const endAt = startAt + duration;
    oscillator.type = waveform;
    oscillator.frequency.setValueAtTime(frequency, startAt);
    oscillator.frequency.exponentialRampToValueAtTime(Math.max(1, endFrequency), endAt);
    gain.gain.setValueAtTime(0.0001, startAt);
    gain.gain.exponentialRampToValueAtTime(volume, startAt + Math.min(0.012, duration / 3));
    gain.gain.exponentialRampToValueAtTime(0.0001, endAt);
    oscillator.connect(gain);
    gain.connect(context.destination);
    oscillator.addEventListener("ended", () => {
      oscillator.disconnect();
      gain.disconnect();
    }, { once: true });
    oscillator.start(startAt);
    oscillator.stop(endAt + 0.01);
  };

  const playSound = (kind, combo = 0) => {
    if (muted) return;
    const context = initializeAudio();
    if (!context) return;
    if (kind === "normal") {
      playTone(context, 440, 780, 0.09, "triangle");
    } else if (kind === "gold") {
      const pitch = 740 + (Math.min(8, combo) * 24);
      playTone(context, pitch, pitch * 1.35, 0.15, "sine");
      playTone(context, pitch * 1.25, pitch * 1.6, 0.18, "sine", 0.07, 0.055);
    } else if (kind === "danger") {
      playTone(context, 145, 78, 0.2, "sawtooth", 0, 0.06);
    } else if (kind === "win") {
      [523, 659, 784, 1_046].forEach((pitch, index) => {
        playTone(context, pitch, pitch * 1.08, 0.2, "sine", index * 0.11, 0.06);
      });
    } else if (kind === "round-over") {
      [220, 165, 110].forEach((pitch, index) => {
        playTone(context, pitch, pitch * 0.82, 0.22, "triangle", index * 0.16, 0.055);
      });
    }
  };

  const loadMutedPreference = () => {
    try {
      muted = window.localStorage.getItem("neen-target-rush-muted") === "true";
    } catch (error) {
      muted = false;
      console.warn("Target Rush could not read the sound preference.", error);
    }
    updateMuteButton();
  };

  const effectPoint = () => {
    if (!(gameScreen instanceof HTMLElement)) return null;
    const targetRect = targetButton.getBoundingClientRect();
    const screenRect = gameScreen.getBoundingClientRect();
    return {
      x: targetRect.left + (targetRect.width / 2) - screenRect.left,
      y: targetRect.top + (targetRect.height / 2) - screenRect.top,
    };
  };

  const setEffectPoint = (element, point) => {
    if (!point) return;
    element.style.setProperty("--rush-effect-x", `${point.x}px`);
    element.style.setProperty("--rush-effect-y", `${point.y}px`);
  };

  const removeAfterAnimation = (element, fallbackMs = 1_000) => {
    element.addEventListener("animationend", () => element.remove(), { once: true });
    window.setTimeout(() => element.remove(), fallbackMs);
  };

  const showHitBurst = (point, type) => {
    if (!(gameScreen instanceof HTMLElement)) return;
    const burst = document.createElement("span");
    burst.className = `rush-hit-burst rush-hit-burst--${type}`;
    burst.setAttribute("aria-hidden", "true");
    setEffectPoint(burst, point);
    gameScreen.append(burst);
    removeAfterAnimation(burst, 500);
  };

  const showScorePopup = (text, type, point) => {
    if (!(gameScreen instanceof HTMLElement)) return;
    const popup = document.createElement("span");
    popup.className = `rush-score-popup rush-score-popup--${type}`;
    popup.textContent = text;
    popup.setAttribute("aria-hidden", "true");
    setEffectPoint(popup, point);
    gameScreen.append(popup);
    removeAfterAnimation(popup, 1_200);
  };

  const flashHazard = () => {
    if (!(gameScreen instanceof HTMLElement)) return;
    gameScreen.classList.remove("is-hazard-hit");
    void gameScreen.offsetWidth;
    gameScreen.classList.add("is-hazard-hit");
    window.setTimeout(() => gameScreen.classList.remove("is-hazard-hit"), 320);
  };

  const updateTargetTimer = () => {
    if (targetButton.hidden) return;
    const elapsed = previewMode && previewState?.active
      ? Date.now() - previewState.startedAt
      : serverElapsed();
    const index = Number(targetButton.dataset.previewIndex ?? Math.floor(elapsed / 1_100));
    const startsAt = index * 1_100;
    const remaining = Math.max(0, Math.min(1, (startsAt + 1_100 - elapsed) / 1_100));
    targetButton.style.setProperty("--rush-target-angle", `${remaining * 360}deg`);
  };

  const animateTargetAppearance = () => {
    targetButton.classList.remove("is-appearing");
    void targetButton.offsetWidth;
    targetButton.classList.add("is-appearing");
  };

  targetButton.addEventListener("animationend", (event) => {
    if (event.animationName === "rush-target-pop") {
      targetButton.classList.remove("is-appearing");
    }
  });

  const renderResults = (current) => {
    document.querySelectorAll("[data-rush-final-score]").forEach((node) => {
      node.textContent = new Intl.NumberFormat().format(current.score);
    });
    document.querySelectorAll("[data-rush-best-combo]").forEach((node) => {
      node.textContent = `x${new Intl.NumberFormat().format(current.best_combo)}`;
    });
    const finalReward = document.querySelector("[data-rush-final-reward]");
    if (finalReward instanceof HTMLElement) {
      finalReward.textContent = `${new Intl.NumberFormat().format(rewardAmount)}`;
    }
  };

  const finishPreviewRound = () => {
    if (!previewState || !previewState.active) return;
    previewState.active = false;
    targetButton.hidden = true;
    window.clearInterval(previewTimer);
    renderResults({ score: previewState.score, best_combo: previewState.bestCombo });
    const finalReward = document.querySelector("[data-rush-final-reward]");
    if (finalReward instanceof HTMLElement && finalReward.parentElement) {
      finalReward.parentElement.textContent = "Reward: none (preview)";
    }
    const won = previewState.score >= Number(goal.textContent.replaceAll(",", ""));
    playSound(won ? "win" : "round-over");
    showScreen(won ? "victory" : "game-over");
    setStatus("Preview finished. Your score was not saved and no Earned Coins were awarded.");
  };

  const updatePreviewRound = () => {
    if (!previewState || !previewState.active) return;
    const elapsed = Date.now() - previewState.startedAt;
    const remaining = Math.max(0, previewDurationMs - elapsed);
    const time = document.querySelector("[data-rush-time]");
    if (time instanceof HTMLElement) time.textContent = formatSeconds(remaining);
    if (remaining === 0) {
      finishPreviewRound();
      return;
    }

    const index = Math.floor(elapsed / previewTargetIntervalMs);
    if (index === previewState.targetIndex) return;
    previewState.targetIndex = index;
    const target = previewState.targets[index];
    if (!target) {
      targetButton.hidden = true;
      return;
    }
    const [x, y] = positions[target.position];
    targetButton.style.setProperty("left", `${x}%`);
    targetButton.style.setProperty("top", `${y}%`);
    targetButton.dataset.kind = target.type;
    targetButton.dataset.previewIndex = String(index);
    targetButton.setAttribute(
      "aria-label",
      target.type === "gold" ? "Hit gold bonus target" : target.type === "danger" ? "Avoid danger target" : "Hit normal target",
    );
    targetButton.disabled = false;
    targetButton.hidden = false;
    animateTargetAppearance();
    updateTargetTimer();
  };

  const startPreviewRound = () => {
    window.clearInterval(previewTimer);
    const targetTypes = Array.from({ length: 28 }, () => {
      const roll = Math.random();
      return roll <= 0.55 ? "normal" : roll <= 0.8 ? "gold" : "danger";
    });
    const positionsByType = {
      normal: ["upper-left", "middle-left"],
      gold: ["upper-right", "middle-right", "lower-left"],
      danger: ["lower-right"],
    };
    previewState = {
      active: true,
      startedAt: Date.now(),
      targetIndex: -1,
      lastHitIndex: null,
      score: 0,
      combo: 0,
      bestCombo: 0,
      targets: targetTypes.map((type) => {
        const available = positionsByType[type];
        return {
          type,
          position: available[Math.floor(Math.random() * available.length)],
        };
      }),
    };
    const score = document.querySelector("[data-rush-score]");
    const combo = document.querySelector("[data-rush-combo]");
    if (score instanceof HTMLElement) score.textContent = "0";
    if (combo instanceof HTMLElement) combo.textContent = "x0";
    updateGoalProgress(0);
    startButton.disabled = true;
    showScreen("game");
    setStatus("Preview only: scores are not saved and no Earned Coins can be won.");
    updatePreviewRound();
    previewTimer = window.setInterval(updatePreviewRound, 100);
  };

  const hitPreviewTarget = () => {
    if (!previewState?.active) return;
    const elapsed = Date.now() - previewState.startedAt;
    if (elapsed >= previewDurationMs) {
      finishPreviewRound();
      return;
    }
    const index = Math.floor(elapsed / previewTargetIntervalMs);
    if (
      index !== previewState.targetIndex
      || targetButton.dataset.previewIndex !== String(index)
      || targetButton.hidden
    ) return;

    const targetType = targetButton.dataset.kind;
    const previousCombo = previewState.combo;
    const point = effectPoint();
    showHitBurst(point, targetType);
    targetButton.hidden = true;
    if (previewState.lastHitIndex !== null && index !== previewState.lastHitIndex + 1) {
      previewState.combo = 0;
    }
    if (targetType === "danger") {
      previewState.score = Math.max(0, previewState.score - 15);
      previewState.combo = 0;
      flashHazard();
      playSound("danger");
      showScorePopup("-15", "danger", point);
      setStatus("Danger hit! Avoid red targets.");
    } else {
      previewState.combo++;
      previewState.bestCombo = Math.max(previewState.bestCombo, previewState.combo);
      const bonus = Math.min(5, previewState.combo - 1);
      const points = targetType === "gold" ? 20 + (bonus * 3) : 10 + (bonus * 2);
      previewState.score += points;
      playSound(targetType, previewState.combo);
      showScorePopup(`+${points}`, targetType, point);
      if (previewState.combo >= 3 && previewState.combo > previousCombo) {
        showScorePopup(`COMBO x${previewState.combo}!`, "combo", point);
      }
      setStatus(`Target hit! +${points} points. Preview scores are not saved.`);
    }
    previewState.lastHitIndex = index;
    const score = document.querySelector("[data-rush-score]");
    const combo = document.querySelector("[data-rush-combo]");
    if (score instanceof HTMLElement) {
      score.textContent = new Intl.NumberFormat().format(previewState.score);
    }
    if (combo instanceof HTMLElement) {
      combo.textContent = `x${new Intl.NumberFormat().format(previewState.combo)}`;
    }
    updateGoalProgress(previewState.score);
  };

  const serverElapsed = () => (
    serverElapsedAnchorMs + (performance.now() - serverElapsedAnchorPerfMs)
  );

  const updateTarget = () => {
    if (!session || !["started", "in_progress"].includes(session.status)) return;
    const elapsed = serverElapsed();
    if (elapsed >= session.round_duration_ms) {
      targetButton.hidden = true;
      finishRound();
      return;
    }
    const index = Math.floor(elapsed / 1_100);
    const targets = session.lookahead_targets ?? [];
    const active = targets.find((target) => target.index === index);
    const next = targets.find((target) => target.index > index);
    if (active && positions[active.position] && !lockedTokens.has(active.token)) {
      const targetChanged = targetButton.hidden || targetButton.dataset.token !== active.token;
      const [x, y] = positions[active.position];
      targetButton.style.setProperty("left", `${x}%`);
      targetButton.style.setProperty("top", `${y}%`);
      targetButton.dataset.kind = active.type;
      targetButton.dataset.token = active.token;
      targetButton.removeAttribute("data-preview-index");
      targetButton.setAttribute(
        "aria-label",
        active.type === "gold" ? "Hit gold bonus target" : active.type === "danger" ? "Avoid danger target" : "Hit normal target",
      );
      targetButton.disabled = pending;
      targetButton.hidden = false;
      if (targetChanged) animateTargetAppearance();
      updateTargetTimer();
    } else {
      targetButton.hidden = true;
      targetButton.removeAttribute("data-token");
      targetButton.classList.remove("is-appearing");
    }

    window.clearTimeout(targetTimer);
    if (next) {
      targetTimer = window.setTimeout(updateTarget, Math.max(0, next.starts_at_ms - elapsed));
    } else if (active) {
      targetTimer = window.setTimeout(updateTarget, Math.max(0, active.ends_at_ms - elapsed));
    } else {
      refreshSession();
    }
  };

  const updateClock = () => {
    if (!session || !["started", "in_progress"].includes(session.status)) return;
    const remaining = Math.max(0, session.round_duration_ms - serverElapsed());
    const time = document.querySelector("[data-rush-time]");
    if (time instanceof HTMLElement) time.textContent = formatSeconds(remaining);
    updateTargetTimer();
    if (remaining === 0) finishRound();
  };

  const renderSession = (current) => {
    const previousStatus = session?.status;
    session = current;
    if (!current) {
      targetButton.hidden = true;
      showScreen("start");
      startButton.disabled = attemptsRemaining <= 0;
      return;
    }
    const score = document.querySelector("[data-rush-score]");
    const combo = document.querySelector("[data-rush-combo]");
    const time = document.querySelector("[data-rush-time]");
    if (score instanceof HTMLElement) score.textContent = new Intl.NumberFormat().format(current.score);
    if (combo instanceof HTMLElement) combo.textContent = `x${new Intl.NumberFormat().format(current.combo)}`;
    if (time instanceof HTMLElement) time.textContent = formatSeconds(current.time_remaining_ms);
    updateGoalProgress(current.score);

    if (current.status === "completed") {
      if (previousStatus !== "completed") playSound("win");
      targetButton.hidden = true;
      renderResults(current);
      showScreen("victory");
      setStatus(`Target Rush cleared! ${new Intl.NumberFormat().format(rewardAmount)} Earned Coins were credited.`);
      window.clearTimeout(targetTimer);
      window.clearInterval(clockTimer);
      return;
    }
    if (current.status === "failed") {
      if (previousStatus !== "failed") playSound("round-over");
      targetButton.hidden = true;
      renderResults(current);
      showScreen("game-over");
      setStatus(`Round over. Reach ${new Intl.NumberFormat().format(Number(goal.textContent.replaceAll(",", "")))} points next time.`);
      window.clearTimeout(targetTimer);
      window.clearInterval(clockTimer);
      return;
    }

    showScreen("game");
    if (current.time_remaining_ms > 0) {
      if (!clockTimer) clockTimer = window.setInterval(updateClock, 100);
      updateTarget();
    }
  };

  const refreshSession = async () => {
    if (!session || pending) return;
    try {
      const result = await api(`/api/games/target-rush/sessions/${session.id}`);
      renderSession(result.session);
    } catch (error) {
      setStatus(error instanceof Error ? error.message : "The game state could not be refreshed.", true);
      window.clearTimeout(targetTimer);
      targetTimer = window.setTimeout(refreshSession, 1000);
    }
  };

  const finishRound = async () => {
    if (!session || pending || finishing || !["started", "in_progress"].includes(session.status)) return;
    finishing = true;
    pending = true;
    targetButton.disabled = true;
    try {
      const result = await api(`/api/games/target-rush/sessions/${session.id}/finish`, "POST", {});
      attemptsRemaining = result.attempts_remaining_today;
      attempts.textContent = String(attemptsRemaining);
      rewardAmount = result.reward?.amount ?? rewardAmount;
      renderSession(result.session);
    } catch (error) {
      if (error.code === "GAME_ROUND_ACTIVE") {
        finishing = false;
        return;
      }
      setStatus(error instanceof Error ? error.message : "The round result could not be verified.", true);
      window.setTimeout(() => {
        finishing = false;
        finishRound();
      }, 1000);
    } finally {
      pending = false;
      targetButton.disabled = false;
      if (session?.status === "completed" || session?.status === "failed") {
        window.clearInterval(clockTimer);
      }
    }
  };

  const startRound = async () => {
    initializeAudio();
    if (previewMode) {
      if (!pending) startPreviewRound();
      return;
    }
    if (previewFallbackAvailable) {
      window.location.assign(`${window.location.pathname}?preview=1`);
      return;
    }
    if (pending || attemptsRemaining <= 0) return;
    pending = true;
    startButton.disabled = true;
    try {
      const result = await api("/api/games/target-rush/sessions", "POST", {
        idempotency_key: crypto.randomUUID(),
      });
      attemptsRemaining = result.attempts_remaining_today;
      attempts.textContent = String(attemptsRemaining);
      rewardAmount = result.reward_amount_coins;
      finishing = false;
      renderSession(result.session);
      setStatus("Hit normal and gold targets; avoid skull targets. Your score and the 30-second timer are verified on the server.");
    } catch (error) {
      setStatus(error instanceof Error ? error.message : "A Target Rush round could not be started.", true);
      startButton.disabled = false;
    } finally {
      pending = false;
      targetButton.disabled = false;
    }
  };

  startButton.addEventListener("click", startRound);
  document.querySelectorAll("[data-rush-again]").forEach((button) => {
    button.addEventListener("click", startRound);
  });
  const activateTarget = async () => {
    if (previewMode) {
      hitPreviewTarget();
      return;
    }
    const token = targetButton.dataset.token;
    if (pending || !session || !token) return;
    const previousScore = session.score;
    const previousCombo = session.combo;
    const targetType = targetButton.dataset.kind;
    if (lockedTokens.has(token)) return;
    lockedTokens.add(token);
    const point = effectPoint();
    showHitBurst(point, targetType);
    pending = true;
    targetButton.disabled = true;
    targetButton.hidden = true;
    try {
      const result = await api(`/api/games/target-rush/sessions/${session.id}/hit`, "POST", { token });
      attemptsRemaining = result.attempts_remaining_today;
      attempts.textContent = String(attemptsRemaining);
      rewardAmount = result.reward?.amount ?? rewardAmount;
      renderSession(result.session);
      if (["started", "in_progress"].includes(result.session.status)) {
        const delta = result.session.score - previousScore;
        if (targetType === "danger") {
          flashHazard();
          playSound("danger");
          showScorePopup("-15", "danger", point);
          setStatus(delta < 0 ? `Danger hit! ${delta} points.` : "Danger hit! Your score is already at zero.");
        } else {
          playSound(targetType, result.session.combo);
          showScorePopup(`+${delta}`, targetType, point);
          if (result.session.combo >= 3 && result.session.combo > previousCombo) {
            showScorePopup(`COMBO x${result.session.combo}!`, "combo", point);
          }
          setStatus(`Target hit! +${delta} points.`);
        }
      }
    } catch (error) {
      lockedTokens.delete(token);
      setStatus(error instanceof Error ? error.message : "That target could not be checked.", true);
      pending = false;
      await refreshSession();
    } finally {
      pending = false;
      targetButton.disabled = false;
    }
  };

  targetButton.addEventListener("pointerdown", (event) => {
    if (!event.isPrimary || event.button !== 0) return;
    lastPointerActivationAt = performance.now();
    void activateTarget();
  });
  targetButton.addEventListener("pointercancel", () => {
    lastPointerActivationAt = -Infinity;
  });
  targetButton.addEventListener("click", (event) => {
    if (event.detail !== 0 && performance.now() - lastPointerActivationAt < 1_000) {
      lastPointerActivationAt = -Infinity;
      return;
    }
    void activateTarget();
  });

  if (muteButton instanceof HTMLButtonElement) {
    muteButton.addEventListener("click", () => {
      muted = !muted;
      saveMutedPreference();
      updateMuteButton();
      if (!muted) initializeAudio();
    });
  }

  const load = async () => {
    loadMutedPreference();
    if (previewMode) {
      goal.textContent = "200";
      attempts.textContent = "Demo only";
      rewardNode.parentElement.textContent = "Reward: none (preview)";
      startButton.disabled = false;
      setStatus("Preview mode: play without signing in. Scores and rewards are not saved.");
      return;
    }
    try {
      const { game } = await api("/api/games/target-rush");
      rewardAmount = game.reward_amount_coins;
      rewardNode.textContent = new Intl.NumberFormat().format(rewardAmount);
      goal.textContent = new Intl.NumberFormat().format(game.score_to_win);
      try {
        const current = await api("/api/games/target-rush/sessions/current");
        attemptsRemaining = current.attempts_remaining_today;
        attempts.textContent = String(attemptsRemaining);
        if (current.session && ["started", "in_progress"].includes(current.session.status)) {
          targetButton.hidden = true;
          showScreen("start");
          startButton.disabled = false;
          setStatus("An active round is waiting. Press Start to resume it.");
        } else if (!current.session) {
          renderSession(null);
          setStatus("Ready when you are. Start a 30-second round to use one of your free daily attempts.");
        } else {
          renderSession(current.session);
        }
      } catch (error) {
        if (error.code === "AUTH_REQUIRED") {
          attempts.textContent = "Sign in required";
          startButton.disabled = true;
          setStatus("Sign in to play Target Rush and earn the server-verified reward.", true);
          return;
        }
        throw error;
      }
    } catch (error) {
      previewFallbackAvailable = true;
      startButton.disabled = false;
      startButton.setAttribute("aria-label", "Open no-reward demo preview");
      const message = error instanceof Error ? error.message : "Target Rush could not be loaded.";
      setStatus(`${message} Press Play to open the no-reward demo preview.`, true);
    }
  };

  load();
})();
