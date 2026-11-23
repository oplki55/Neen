(() => {
  const canvas = document.querySelector("[data-island-canvas]");
  const statusNode = document.querySelector("[data-status]");
  const zoneNode = document.querySelector("[data-zone-name]");
  const minimap = document.querySelector(".sunny-minimap");
  const mapToggle = document.querySelector("[data-map-toggle]");
  const codexDialog = document.querySelector("[data-codex-dialog]");
  const codexGrid = document.querySelector("[data-codex-grid]");
  const objectivesNode = document.querySelector("[data-objectives]");
  const startButton = document.querySelector("[data-adventure-start]");
  const completeButton = document.querySelector("[data-adventure-complete]");
  const avatarSelect = document.querySelector("[data-avatar-select]");
  const avatarPortrait = document.querySelector("[data-avatar-portrait]");
  const actionButton = document.querySelector("[data-action-button]");
  const touchActionButton = document.querySelector("[data-touch-action]");
  const jumpButton = document.querySelector("[data-jump-button]");
  const dashButton = document.querySelector("[data-dash-button]");
  const joystick = document.querySelector("[data-joystick]");
  const joystickKnob = document.querySelector("[data-joystick-knob]");
  if (
    !(canvas instanceof HTMLCanvasElement)
    || !(statusNode instanceof HTMLElement)
    || !(zoneNode instanceof HTMLElement)
    || !(minimap instanceof HTMLElement)
    || !(mapToggle instanceof HTMLButtonElement)
    || !(codexDialog instanceof HTMLDialogElement)
    || !(codexGrid instanceof HTMLElement)
    || !(objectivesNode instanceof HTMLElement)
    || !(startButton instanceof HTMLButtonElement)
    || !(completeButton instanceof HTMLButtonElement)
  ) return;

  const context = canvas.getContext("2d", { alpha: false });
  if (!context) {
    statusNode.textContent = "Your browser could not start the island canvas.";
    statusNode.classList.add("is-error");
    return;
  }

  const WORLD = { width: 1600, height: 1000, viewWidth: 1000, viewHeight: 620 };
  const ZONES = {
    neon_pier: "Moonlit Stilt Bay",
    palm_grove: "Crownleaf Manor",
    loot_canyon: "Skycrate Foundry",
    sunset_ridge: "Solstice Bellspire",
  };
  const ZONES_AR = {
    neon_pier: "خليج الأكواخ المضيء",
    palm_grove: "قصر أوراق التاج",
    loot_canyon: "مصنع الصناديق المعلقة",
    sunset_ridge: "برج جرس الانقلاب الشمسي",
  };
  const isArabic = () =>
    document.documentElement.lang?.toLowerCase().startsWith("ar") ||
    document.documentElement.dir === "rtl";

  const zoneName = (zone) => (isArabic() ? ZONES_AR[zone] : ZONES[zone]) || zone;

  const OBJECTIVE_TEXT_AR = {
    find_relic: "اعثر على البوصلة الذهبية في وادي الغنائم",
    collect_drops: "اجمع ثلاث قطرات شمسية في بستان النخيل",
    reach_sunset_ridge: "اوصل إلى نقطة المراقبة في قمة الغروب",
    activate_beacons: "فعّل المنارتين القديمتين في الرصيف المضيء",
  };

  const translateObjectiveText = (objective) => {
    if (!isArabic()) {
      return objective.description || objective.id.replaceAll("_", " ");
    }
    if (objective.id && OBJECTIVE_TEXT_AR[objective.id]) {
      return OBJECTIVE_TEXT_AR[objective.id];
    }
    const raw = String(objective.description || "").trim();
    const lower = raw.toLowerCase();
    if (lower.includes("golden compass")) return OBJECTIVE_TEXT_AR.find_relic;
    if (lower.includes("sunny drops") || lower.includes("three sunny")) return OBJECTIVE_TEXT_AR.collect_drops;
    if (lower.includes("lookout") || lower.includes("sunset ridge")) return OBJECTIVE_TEXT_AR.reach_sunset_ridge;
    if (lower.includes("beacon")) return OBJECTIVE_TEXT_AR.activate_beacons;
    return raw || objective.id.replaceAll("_", " ");
  };
  const PORTALS = {
    neon_pier: [
      { x: 1475, y: 565, zone: "palm_grove", label: "Follow the boardwalk to Crownleaf Manor" },
    ],
    palm_grove: [
      { x: 115, y: 785, zone: "neon_pier", label: "Return to Moonlit Stilt Bay" },
      { x: 1480, y: 665, zone: "loot_canyon", label: "Take the foundry road" },
    ],
    loot_canyon: [
      { x: 115, y: 815, zone: "palm_grove", label: "Return to Crownleaf Manor" },
      { x: 1480, y: 815, zone: "sunset_ridge", label: "Climb to Solstice Bellspire" },
    ],
    sunset_ridge: [
      { x: 140, y: 815, zone: "loot_canyon", label: "Descend to Skycrate Foundry" },
    ],
  };
  const GUARDIANS = [
    {
      id: "guardian_pier",
      zone: "neon_pier",
      portalZone: "palm_grove",
      x: 1365,
      y: 565,
      maxHp: 4,
      name: "Tideclaw Guardian",
      theme: "aqua",
      primary: "#1e6f7d",
      secondary: "#5ff5ff",
      eye: "#ff5a8a",
    },
    {
      id: "guardian_grove",
      zone: "palm_grove",
      portalZone: "loot_canyon",
      x: 1370,
      y: 665,
      maxHp: 4,
      name: "Stonebark Sentinel",
      theme: "forest",
      primary: "#556b4f",
      secondary: "#9dff8c",
      eye: "#ffb84d",
    },
    {
      id: "guardian_canyon",
      zone: "loot_canyon",
      portalZone: "sunset_ridge",
      x: 1370,
      y: 815,
      maxHp: 4,
      name: "Prism Crystal Titan",
      theme: "crystal",
      primary: "#4a386b",
      secondary: "#d28cff",
      eye: "#ff4fa3",
    },
    {
      id: "guardian_ridge",
      zone: "sunset_ridge",
      portalZone: "loot_canyon",
      x: 255,
      y: 815,
      maxHp: 4,
      name: "Solstice Ember Boss",
      theme: "solar",
      primary: "#6e2c3b",
      secondary: "#ffbe55",
      eye: "#fff3a8",
    },
  ];

  const getGuardianState = (guardian) => {
    if (!state.guardianStates[guardian.id]) {
      state.guardianStates[guardian.id] = {
        hp: guardian.maxHp,
        hitFlashUntil: 0,
        lastAttackAt: 0,
        chargeUntil: 0,
      };
    }
    return state.guardianStates[guardian.id];
  };

  const activeGuardiansInZone = (zone) =>
    GUARDIANS.filter((g) => g.zone === zone && !state.defeatedGuardians.has(g.id));

  const guardianBlockingPortal = (zone, targetZone) =>
    GUARDIANS.find(
      (g) =>
        g.zone === zone &&
        g.portalZone === targetZone &&
        !state.defeatedGuardians.has(g.id)
    ) || null;
  const PICKUPS = [
    { id: "snoop_badge", zone: "neon_pier", x: 390, y: 690, label: "Snoop Badge" },
    { id: "golden_compass", zone: "loot_canyon", x: 945, y: 515, label: "Golden Compass" },
    { id: "neon_relic", zone: "loot_canyon", x: 475, y: 715, label: "Neon Relic" },
    { id: "drift_token", zone: "sunset_ridge", x: 560, y: 700, label: "Drift Token" },
    { id: "sun_crate", zone: "palm_grove", x: 385, y: 690, label: "Sun Crate" },
    { id: "palm_pack", zone: "palm_grove", x: 895, y: 430, label: "Palm Pack" },
    { id: "reef_sticker", zone: "palm_grove", x: 1215, y: 700, label: "Reef Sticker" },
    { id: "sunset_sticker", zone: "neon_pier", x: 755, y: 340, label: "Sunset Sticker" },
    { id: "canyon_sticker", zone: "sunset_ridge", x: 1165, y: 720, label: "Canyon Sticker" },
  ];
  const AVATAR_LOOKS = [
    { id: "snoop-sticker-01", name: "Moonlit Blue", jacket: "#18294c", sleeve: "#2787c5", accent: "#80f8ff", trim: "#e5bf61" },
    { id: "snoop-sticker-02", name: "Rose Pulse", jacket: "#302345", sleeve: "#bd58a2", accent: "#ff9bda", trim: "#f2d486" },
    { id: "snoop-sticker-03", name: "Starflare", jacket: "#24304d", sleeve: "#3b86df", accent: "#b7e5ff", trim: "#e9b957" },
    { id: "snoop-sticker-04", name: "Crown Gold", jacket: "#29334b", sleeve: "#c77a35", accent: "#ffd87a", trim: "#f8e9ba" },
    { id: "snoop-sticker-05", name: "Neon Violet", jacket: "#241f42", sleeve: "#7658d6", accent: "#d4adff", trim: "#f3cc68" },
    { id: "snoop-sticker-06", name: "Glacier", jacket: "#193b4a", sleeve: "#2f98a9", accent: "#9ef6ff", trim: "#e7c56e" },
    { id: "snoop-sticker-07", name: "Sakura", jacket: "#30253f", sleeve: "#d2678f", accent: "#ffc1db", trim: "#f5d276" },
    { id: "snoop-sticker-08", name: "Victory Mint", jacket: "#1d384b", sleeve: "#25a98d", accent: "#8df3d5", trim: "#eec55f" },
    { id: "snoop-sticker-09", name: "Sunset Ember", jacket: "#3a293d", sleeve: "#df704f", accent: "#ffc078", trim: "#f3d16c" },
  ];
  const AVATAR_STORAGE_KEY = "neen_sunny_avatar";
  const readSavedAvatar = () => {
    try {
      const saved = window.localStorage.getItem(AVATAR_STORAGE_KEY);
      return AVATAR_LOOKS.some((avatar) => avatar.id === saved)
        ? saved
        : AVATAR_LOOKS[0].id;
    } catch {
      return AVATAR_LOOKS[0].id;
    }
  };
  const AMBIENT_ART = Array.from({ length: 5 }, (_, index) => ({
    zone: ["neon_pier", "palm_grove", "loot_canyon", "sunset_ridge", "palm_grove"][index],
    x: [1140, 650, 1290, 930, 1350][index],
    y: [715, 285, 340, 300, 290][index],
    imageId: `snoop-sticker-${String(index + 5).padStart(2, "0")}`,
  }));
  const BEACONS = [
    { id: "pier-beacon-a", zone: "neon_pier", x: 800, y: 440, label: "West Beacon" },
    { id: "pier-beacon-b", zone: "neon_pier", x: 1110, y: 600, label: "East Beacon" },
  ];
  const QUEST_BOARD = { id: "quest-board", zone: "neon_pier", x: 290, y: 530, label: "Daily Adventure Board" };
  const DROP_MARKER = { id: "drop-marker", zone: "palm_grove", x: 350, y: 495, label: "Crownleaf Supply Crates" };
  const BELLSPIRE = { id: "summit-bellspire", zone: "sunset_ridge", x: 1330, y: 390, label: "Solstice Bellspire" };
  const SUPPLY_DROP = { id: "foundry-air-drop", zone: "loot_canyon", x: 1260, y: 735, label: "Timed Foundry Air Drop" };
  const INTERACTION_RADIUS = 74;
  const colors = {
    ink: "#24495a",
    white: "#fffdf1",
    teal: "#52b7bb",
    coral: "#ed8054",
    gold: "#f2c65d",
    green: "#73b87b",
  };
  const state = {
    csrfToken: "",
    busy: false,
    authenticated: false,
    session: null,
    progress: {
      xp: 0,
      level: 1,
      unlocked_collectibles: [],
      discovered_zones: [],
      total_adventures_completed: 0,
      last_position: null,
    },
    codex: [],
    avatarId: readSavedAvatar(),
    adventure: { attempts_remaining_today: 0, free_attempts_per_day: 3, reward_amount_coins: 50 },
    earnedCoins: null,
    position: { zone: "neon_pier", x: 150, y: 520 },
    facing: { x: 1, y: 0 },
    keys: new Set(),
    joystickVector: { x: 0, y: 0 },
    joystickPointer: null,
    images: new Map(),
    particles: [],
    beaconActivations: new Set(),
    activeInteraction: null,
    interactionKey: "",
    dirty: false,
    progressTimer: 0,
    lastPeriodicSync: 0,
    lastFrame: 0,
    jumpUntil: 0,
    dashUntil: 0,
    dashCooldownUntil: 0,
    toastUntil: 0,
    toastMessage: "",
    victoryTimer: 0,
    animationFrame: 0,
    startedAt: performance.now(),
    lastDashHudUpdate: 0,
    floatingTexts: [],
    beams: [],
    enemyWaves: [],
    defeatedGuardians: new Set(),
    guardianStates: {},
    lastBeamAt: 0,
    playerHitUntil: 0,
  };

  const api = async (path, method = "GET", data = null) => {
    const headers = { Accept: "application/json" };
    const options = { method, credentials: "same-origin", cache: "no-store", headers };
    if (data !== null) {
      if (!state.csrfToken) {
        const csrfResponse = await fetch("/api/auth/csrf", {
          headers: { Accept: "application/json" },
          credentials: "same-origin",
          cache: "no-store",
        });
        let csrfResult;
        try { csrfResult = await csrfResponse.json(); } catch { throw new Error("The security token could not be read."); }
        if (!csrfResponse.ok || csrfResult.success !== true) {
          throw new Error(csrfResult.errors?.[0]?.message || "A security token is required.");
        }
        state.csrfToken = csrfResult.data.csrf_token;
      }
      headers["Content-Type"] = "application/json";
      headers["X-CSRF-Token"] = state.csrfToken;
      options.body = JSON.stringify(data);
    }
    const response = await fetch(path, options);
    let result;
    try { result = await response.json(); } catch { throw new Error("The island service returned an unreadable response."); }
    if (!response.ok || result.success !== true) {
      const error = new Error(result.errors?.[0]?.message || "The island request could not be completed.");
      error.code = result.errors?.[0]?.code;
      error.status = response.status;
      throw error;
    }
    return result.data;
  };

  const setStatus = (message, isError = false) => {
    statusNode.textContent = message;
    statusNode.classList.toggle("is-error", isError);
  };

  const setAvatar = (avatarId, persist = false) => {
    const avatar = AVATAR_LOOKS.find((item) => item.id === avatarId);
    if (!avatar) return;
    state.avatarId = avatar.id;
    if (avatarPortrait instanceof HTMLCanvasElement) {
      avatarPortrait.setAttribute("aria-label", `NEEN avatar: ${avatar.name}`);
      drawAvatarPortrait(avatar);
    }
    if (avatarSelect instanceof HTMLSelectElement) avatarSelect.value = avatar.id;
    if (!persist) return;
    try {
      window.localStorage.setItem(AVATAR_STORAGE_KEY, avatar.id);
      setStatus(`${avatar.name} look selected and saved in this browser.`);
    } catch {
      setStatus("Look changed, but your browser could not save this selection.", true);
    }
  };

  const formatNumber = (value) => new Intl.NumberFormat().format(Number(value) || 0);

  const imageFor = (id) => state.images.get(id);

  const loadImages = (catalog) => {
    for (const item of catalog) {
      if (state.images.has(item.id)) continue;
      const image = new Image();
      image.decoding = "async";
      image.src = item.image;
      image.addEventListener("load", () => state.dirty = true, { once: true });
      state.images.set(item.id, image);
    }
    for (const ambient of AMBIENT_ART) {
      if (state.images.has(ambient.imageId)) continue;
      const image = new Image();
      image.decoding = "async";
      image.src = `/assets/hero-loot/${ambient.imageId}.webp`;
      image.addEventListener("load", () => state.dirty = true, { once: true });
      state.images.set(ambient.imageId, image);
    }
  };

  const updateProgress = (progress) => {
    if (!progress) return;
    state.progress = {
      xp: Number(progress.xp) || 0,
      level: Number(progress.level) || 1,
      unlocked_collectibles: Array.isArray(progress.unlocked_collectibles) ? progress.unlocked_collectibles : [],
      discovered_zones: Array.isArray(progress.discovered_zones) ? progress.discovered_zones : [],
      total_adventures_completed: Number(progress.total_adventures_completed) || 0,
      last_position: progress.last_position || null,
    };
    renderProgress();
  };

  const updateSession = (session) => {
    state.session = session || null;
    renderObjectives();
  };

  const levelProgressFromXp = (rawXp) => {
    const xp = Math.max(0, Math.floor(Number(rawXp) || 0));
    const level = Math.min(100, Math.floor(xp / 300) + 1);
    return {
      level,
      currentXp: level >= 100 ? 0 : xp % 300,
      requiredXp: 300,
    };
  };

  const syncIslandBrandTitle = () => {
    const ar = isArabic();
    const h1 = document.getElementById("sunny-title");
    if (h1) {
      h1.innerHTML = ar ? "جزيرة <span>نينو</span>" : "NENO <span>LAND</span>";
    }
    const crumb = document.querySelector(".sunny-crumbs span:last-child");
    if (crumb) {
      crumb.textContent = ar ? "جزيرة نينو" : "NeNo Land";
    }
    document.title = ar ? "جزيرة نينو — NEEN" : "NeNo Land — NEEN";
  };

  const renderProgress = () => {
    syncIslandBrandTitle();
    const ar = isArabic();
    const lvlInfo = levelProgressFromXp(state.progress.xp);
    const level = Math.max(1, lvlInfo.level);
    const levelNode = document.querySelector("[data-level]");
    const levelLabel = document.querySelector("[data-level-label]");
    const xpFill = document.querySelector("[data-xp-fill]");
    const xpLabel = document.querySelector("[data-xp-label]");
    const codexCount = document.querySelector("[data-codex-count]");
    if (levelNode) levelNode.textContent = String(level);
    if (levelLabel) levelLabel.textContent = String(level);
    if (xpFill) xpFill.style.width = `${Math.min(100, (lvlInfo.currentXp / lvlInfo.requiredXp) * 100)}%`;
    if (xpLabel) xpLabel.textContent = `${formatNumber(lvlInfo.currentXp)} / ${formatNumber(lvlInfo.requiredXp)} XP`;
    if (codexCount) codexCount.textContent = `${state.progress.unlocked_collectibles.length}/${state.codex.length || 9}`;

    const attempts = document.querySelector("[data-attempts]");
    const rewardNode = document.querySelector("[data-reward]");
    if (attempts) {
      attempts.textContent = ar
        ? `المغامرات اليومية: ${state.adventure.attempts_remaining_today} / ${state.adventure.free_attempts_per_day} متبقية`
        : `Daily adventures: ${state.adventure.attempts_remaining_today} / ${state.adventure.free_attempts_per_day} left`;
    }
    if (rewardNode) {
      rewardNode.textContent = state.authenticated
        ? formatNumber(state.adventure.reward_amount_coins)
        : "—";
    }
    const balance = document.querySelector("[data-earned-balance]");
    if (balance) balance.textContent = state.earnedCoins === null ? "—" : formatNumber(state.earnedCoins);
    startButton.disabled = !state.authenticated || state.busy
      || Boolean(state.session && ["started", "in_progress"].includes(state.session.status));
    if (state.session && ["started", "in_progress"].includes(state.session.status)) {
      startButton.textContent = ar ? "المغامرة جارية الآن" : "Adventure in Progress";
    } else if (state.adventure.attempts_remaining_today > 0) {
      startButton.textContent = ar ? "ابدأ مغامرة اليوم" : "Start Daily Adventure";
    } else {
      startButton.textContent = ar ? "محاولة إضافية – 100 كوينز" : "Extra attempt – 100 Coins";
    }
    renderCodex();
    renderObjectives();
  };

  const renderObjectives = () => {
    const ar = isArabic();
    objectivesNode.replaceChildren();
    const title = document.querySelector("[data-quest-title]");
    const subtitle = document.querySelector("[data-quest-subtitle]");
    if (!state.session || !["started", "in_progress"].includes(state.session.status)) {
      if (title) title.textContent = ar ? "تجوال حر" : "Free Roam";
      if (subtitle) {
        subtitle.textContent = ar
          ? "اجمع الكنوز واستكشف كل المناطق."
          : "Pick up treasures and explore every zone.";
      }
      const empty = document.createElement("p");
      empty.className = "sunny-empty-objectives";
      empty.textContent = state.authenticated
        ? (ar ? "ابدأ مغامرة يومية لكشف أهدافك الثلاثة في الجزيرة." : "Start a daily adventure to reveal your three island objectives.")
        : (ar ? "سجّل الدخول لحفظ اكتشافاتك وبدء مغامرة يومية." : "Sign in to save discoveries and start a daily adventure.");
      objectivesNode.append(empty);
      if (state.authenticated) {
        const quickStart = document.createElement("button");
        quickStart.type = "button";
        quickStart.className = "sunny-main-button";
        quickStart.style.marginTop = "0.75rem";
        quickStart.textContent = state.adventure.attempts_remaining_today === 0
          ? (ar ? "محاولة إضافية – 100 كوينز" : "Extra attempt – 100 Coins")
          : (ar ? "اكشف أهداف اليوم الثلاثة" : "Reveal Today's 3 Objectives");
        quickStart.addEventListener("click", () => void startAdventure());
        objectivesNode.append(quickStart);
      }
      completeButton.hidden = true;
      return;
    }
    if (title) title.textContent = ar ? "مغامرة اليوم" : "Today's Adventure";
    if (subtitle) {
      subtitle.textContent = ar
        ? "استكشف الجزيرة وأكمل كل هدف محدد من الخادم."
        : "Explore the island and finish each server-assigned objective.";
    }
    const objectives = Array.isArray(state.session.objectives) ? state.session.objectives : [];
    for (const objective of objectives) {
      const row = document.createElement("div");
      row.className = `sunny-objective${objective.completed ? " is-complete" : ""}`;
      const check = document.createElement("span");
      check.className = "sunny-objective-check";
      check.textContent = objective.completed ? "✓" : "•";
      const copy = document.createElement("span");
      const titleText = document.createElement("strong");
      titleText.textContent = translateObjectiveText(objective);
      const zoneText = document.createElement("small");
      zoneText.textContent = zoneName(objective.zone);
      copy.append(titleText, zoneText);
      row.append(check, copy);
      objectivesNode.append(row);
    }
    const complete = objectives.length > 0 && objectives.every((objective) => objective.completed);
    completeButton.hidden = !complete;
    completeButton.disabled = state.busy || !complete;
    completeButton.textContent = ar ? "إكمال المغامرة واستلام المكافأة" : "Complete Adventure";
  };

  const renderCodex = () => {
    codexGrid.replaceChildren();
    const found = new Set(state.progress.unlocked_collectibles);
    for (const collectible of state.codex) {
      const item = document.createElement("article");
      const isFound = found.has(collectible.id);
      item.className = `sunny-codex-item ${isFound ? "is-found" : "is-locked"}`;
      const art = document.createElement("div");
      art.className = "sunny-codex-art";
      const image = document.createElement("img");
      image.src = collectible.image;
      image.alt = isFound ? collectible.name : "Undiscovered island collectible";
      image.loading = "lazy";
      art.append(image);
      const title = document.createElement("strong");
      title.textContent = collectible.name;
      const label = document.createElement("small");
      label.textContent = isFound ? "Discovered" : "Not found yet";
      item.append(art, title, label);
      codexGrid.append(item);
    }
    const summary = document.querySelector("[data-codex-summary]");
    if (summary) {
      summary.textContent = `${found.size} of ${state.codex.length} keepsakes discovered. Your finds are saved to your account.`;
    }
  };

  const renderMap = () => {
    document.querySelectorAll("[data-map-zone]").forEach((node) => {
      const zone = node.getAttribute("data-map-zone");
      node.classList.toggle("is-current", zone === state.position.zone);
      node.classList.toggle("is-discovered", state.progress.discovered_zones.includes(zone));
    });
    const current = ZONES[state.position.zone] || "NeNo Land";
    const miniLabel = document.querySelector("[data-minimap-coords]");
    if (miniLabel) miniLabel.textContent = current.toUpperCase();
    zoneNode.textContent = current;
    const point = {
      neon_pier: [37, 92],
      palm_grove: [108, 38],
      loot_canyon: [179, 103],
      sunset_ridge: [183, 24],
    }[state.position.zone] || [37, 92];
    const marker = document.querySelector("[data-map-player]");
    if (marker) {
      marker.setAttribute("cx", String(point[0]));
      marker.setAttribute("cy", String(point[1]));
    }
  };

  const toast = (message, duration = 2400) => {
    const node = document.querySelector("[data-toast]");
    if (!(node instanceof HTMLElement)) return;
    state.toastMessage = message;
    state.toastUntil = performance.now() + duration;
    node.textContent = message;
    node.hidden = false;
  };

  const celebrateVictory = () => {
    const banner = document.querySelector("[data-victory-banner]");
    if (!(banner instanceof HTMLElement)) return;
    window.clearTimeout(state.victoryTimer);
    banner.hidden = false;
    banner.classList.remove("is-celebrating");
    void banner.offsetWidth;
    banner.classList.add("is-celebrating");
    state.victoryTimer = window.setTimeout(() => {
      banner.hidden = true;
      banner.classList.remove("is-celebrating");
    }, 4200);
  };

  let progressQueue = Promise.resolve();
  const syncProgress = (extra = null) => {
    if (!state.authenticated) {
      setStatus("Sign in to save your island discoveries.", true);
      return Promise.reject(new Error("Sign in to save progress."));
    }
    const request = progressQueue.catch(() => {}).then(async () => {
      if (extra === null) {
        const result = await api("/api/games/sunny-island/state");
        return { progress: result.state.progress, session: result.state.adventure.active_session };
      }
      const nonce = state.session?.action_nonce;
      if (typeof nonce !== "string" || nonce === "") {
        throw new Error("The Sunny Island action nonce is unavailable.");
      }
      const requestKey = globalThis.crypto?.randomUUID
        ? globalThis.crypto.randomUUID()
        : `sunny-action-${Date.now()}-${Math.random().toString(36).slice(2, 12)}`;
      return api("/api/games/sunny-island/action", "POST", {
        session_id: extra.session_id,
        request_key: requestKey,
        action_nonce: nonce,
        action: extra.action,
        target_id: extra.target_id,
      });
    });
    progressQueue = request.catch(() => {});
    return request.then((result) => {
      updateProgress(result.progress);
      if (result.session) updateSession(result.session);
      state.dirty = false;
      renderMap();
      return result;
    });
  };

  const scheduleProgressSync = (immediate = false) => {
    if (!state.authenticated) return;
    state.dirty = true;
    window.clearTimeout(state.progressTimer);
    const delay = immediate ? 0 : 650;
    state.progressTimer = window.setTimeout(() => {
      syncProgress().catch((error) => setStatus(error.message, true));
    }, delay);
  };

  const refreshWallet = async () => {
    if (!state.authenticated) return;
    const wallet = await api("/api/wallet");
    state.earnedCoins = Number(wallet.earned_coin_balance) || 0;
    renderProgress();
  };

  const loadState = async () => {
    try {
      const result = await api("/api/games/sunny-island/state");
      state.authenticated = true;
      updateProgress(result.state.progress);
      state.codex = result.state.codex;
      state.adventure = result.state.adventure;
      loadImages(state.codex);
      if (result.state.progress.last_position) {
        state.position = { ...result.state.progress.last_position };
      }
      updateSession(result.state.adventure.active_session);
      renderProgress();
      renderMap();
      await refreshWallet().catch(() => {
        state.earnedCoins = null;
        renderProgress();
      });
      setStatus(state.session ? "Your daily adventure is ready to continue." : "Welcome to NeNo Land. Explore freely or start today's adventure.");
      scheduleProgressSync(true);
    } catch (error) {
      state.authenticated = false;
      const fallbackCatalog = [
        { id: "golden_compass", name: "Golden Compass", image: "/assets/hero-loot/gold-coin.webp" },
        { id: "neon_relic", name: "Neon Relic", image: "/assets/hero-loot/weapon-ak47.webp" },
        { id: "drift_token", name: "Drift Token", image: "/assets/hero-loot/weapon-mp40.webp" },
        { id: "sun_crate", name: "Sun Crate", image: "/assets/hero-loot/loot-crate.webp" },
        { id: "palm_pack", name: "Palm Pack", image: "/assets/hero-loot/backpack.webp" },
        { id: "snoop_badge", name: "Snoop Badge", image: "/assets/hero-loot/snoop-sticker-01.webp" },
        { id: "reef_sticker", name: "Reef Sticker", image: "/assets/hero-loot/snoop-sticker-02.webp" },
        { id: "sunset_sticker", name: "Sunset Sticker", image: "/assets/hero-loot/snoop-sticker-03.webp" },
        { id: "canyon_sticker", name: "Canyon Sticker", image: "/assets/hero-loot/snoop-sticker-04.webp" },
      ];
      state.codex = fallbackCatalog;
      loadImages(state.codex);
      updateSession(null);
      renderProgress();
      renderMap();
      const statusText = error.code === "AUTH_REQUIRED"
        ? "Sign in to save progress and earn rewards. You can still explore the island."
        : (error instanceof Error ? error.message : "The island could not be loaded.");
      setStatus(statusText, error.code !== "AUTH_REQUIRED");
    }
  };

  const nearest = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
  const objectsForZone = (zone) => {
    const objects = [];
    for (const pickup of PICKUPS) {
      if (pickup.zone === zone && !state.progress.unlocked_collectibles.includes(pickup.id)) {
        objects.push({ ...pickup, type: "collectible" });
      }
    }
    for (const beacon of BEACONS) {
      if (beacon.zone === zone && !state.beaconActivations.has(beacon.id)) {
        objects.push({ ...beacon, type: "beacon" });
      }
    }
    if (QUEST_BOARD.zone === zone) objects.push({ ...QUEST_BOARD, type: "board" });
    if (DROP_MARKER.zone === zone) objects.push({ ...DROP_MARKER, type: "drops" });
    if (BELLSPIRE.zone === zone) objects.push({ ...BELLSPIRE, type: "bellspire" });
    for (const portal of PORTALS[zone] || []) {
      objects.push({ ...portal, type: "portal" });
    }
    return objects;
  };

  const updateInteraction = () => {
    const objects = objectsForZone(state.position.zone);
    if (state.position.zone === SUPPLY_DROP.zone && isSupplyDropActive(performance.now())) {
      objects.push({ ...SUPPLY_DROP, type: "supply" });
    }
    const object = objects
      .map((item) => ({ ...item, distance: nearest(state.position, item) }))
      .filter((item) => item.distance <= INTERACTION_RADIUS)
      .sort((a, b) => a.distance - b.distance)[0] || null;
    state.activeInteraction = object;
    const panel = document.querySelector("[data-interaction]");
    const label = document.querySelector("[data-interaction-label]");
    if (!(panel instanceof HTMLElement) || !(label instanceof HTMLElement)) return;
    const key = object ? `${object.type}:${object.id}` : "";
    if (key !== state.interactionKey) {
      state.interactionKey = key;
      if (object) {
        const prefix = window.matchMedia("(pointer: coarse)").matches ? "Tap action" : "E / Space";
        label.textContent = `${prefix} · ${object.label}`;
      }
    }
    panel.hidden = !object;
  };

  const objectiveFor = (id) => state.session?.objectives?.find((objective) => objective.id === id && !objective.completed) || null;

  const markObjective = async (id) => {
    const objective = objectiveFor(id);
    if (!objective || state.busy) return false;
    if (state.position.zone !== objective.zone) {
      toast(`Travel to ${ZONES[objective.zone]} to finish this objective.`);
      return false;
    }
    if (id === "collect_drops" && state.progress.unlocked_collectibles.length < Number(objective.target_count || 3)) {
      toast(`Discover ${Math.max(0, Number(objective.target_count || 3) - state.progress.unlocked_collectibles.length)} more keepsake(s) first.`);
      return false;
    }
    if (id === "find_relic" && !state.progress.unlocked_collectibles.includes("golden_compass")) {
      toast(`Find the Golden Compass in ${ZONES.loot_canyon} first.`);
      return false;
    }
    try {
      const result = await syncProgress();
      if (result.session) updateSession(result.session);
      toast(`Objective complete · ${objective.description}`, 3000);
      setStatus(`${objective.description} Complete!`);
      return true;
    } catch (error) {
      setStatus(error instanceof Error ? error.message : "Objective progress could not be saved.", true);
      return false;
    } finally {
      state.busy = false;
      renderProgress();
    }
  };

  const pickUp = async (item) => {
    if (state.progress.unlocked_collectibles.includes(item.id)) return;
    if (!state.authenticated) {
      toast("Sign in to add this treasure to your Codex.");
      return;
    }
    state.busy = true;
    renderProgress();
    try {
      const result = await syncProgress({
        session_id: state.session.id,
        action: "collect",
        target_id: item.id,
      });
      if (result.progress) updateProgress(result.progress);
      spawnBurst(state.position.x, state.position.y, "#ffe078", 18);
      addFloatingText(state.position.x, state.position.y - 42, `+ ${item.label}`, "#fff9ce");
      toast(`${item.label} added to your Island Codex!`, 2800);
      setStatus(`${item.label} discovered.`);
      state.busy = false;
      renderProgress();
      if (item.id === "golden_compass" && state.position.zone === "loot_canyon") {
        await markObjective("find_relic");
      }
      if (state.position.zone === "palm_grove"
        && state.progress.unlocked_collectibles.length >= 3
        && objectiveFor("collect_drops")) {
        await markObjective("collect_drops");
      }
    } catch (error) {
      setStatus(error instanceof Error ? error.message : "This treasure could not be saved.", true);
    } finally {
      state.busy = false;
      renderProgress();
      updateInteraction();
    }
  };

  const enterZone = async (zone) => {
    const entrance = {
      neon_pier: { x: 240, y: 565 },
      palm_grove: { x: 260, y: 785 },
      loot_canyon: { x: 260, y: 815 },
      sunset_ridge: { x: 780, y: 825 },
    }[zone] || { x: 760, y: 780 };
    state.position = { zone, ...entrance };
    state.facing = zone === "neon_pier" ? { x: -1, y: 0 } : { x: 1, y: 0 };
    state.progress.discovered_zones = [...new Set([...state.progress.discovered_zones, zone])];
    renderMap();
    spawnBurst(state.position.x, state.position.y, "#fff5c3", 12);
    toast(isArabic() ? `مرحباً بك في ${zoneName(zone)}!` : `Welcome to ${zoneName(zone)}!`, 2200);
    setStatus(`Entered ${ZONES[zone]}. Your location is being saved.`);
    await syncProgress({
      session_id: state.session.id,
      action: "enter_zone",
      target_id: zone,
    });
    if (zone === "palm_grove" && state.progress.unlocked_collectibles.length >= 3) {
      await markObjective("collect_drops");
    }
    if (zone === "sunset_ridge") {
      await markObjective("reach_sunset_ridge");
    }
    updateInteraction();
  };

  const activateBeacon = async (beacon) => {
    state.beaconActivations.add(beacon.id);
    spawnBurst(beacon.x, beacon.y - 20, "#b4f7dc", 14);
    if (state.session) {
      try {
        await syncProgress({
          session_id: state.session.id,
          action: "activate_beacon",
          target_id: beacon.id,
        });
      } catch (error) {
        state.beaconActivations.delete(beacon.id);
        setStatus(error instanceof Error ? error.message : "The beacon could not be activated.", true);
        updateInteraction();
        return;
      }
    }
    if (state.beaconActivations.size < 2) {
      toast(`Beacon lit · ${2 - state.beaconActivations.size} more to go!`);
      setStatus("A pier beacon is humming. Find and activate the other beacon.");
      updateInteraction();
      return;
    }
    if (objectiveFor("activate_beacons")) await markObjective("activate_beacons");
    else toast("Both ancient beacons are glowing!");
    updateInteraction();
  };

  const interact = async () => {
    const item = state.activeInteraction;
    if (!item || state.busy) return;
    if (item.type === "collectible") {
      await pickUp(item);
    } else if (item.type === "beacon") {
      await activateBeacon(item);
    } else if (item.type === "board") {
      if (state.session && ["started", "in_progress"].includes(state.session.status)) {
        toast("Your daily adventure is already in progress.");
      } else {
        await startAdventure();
      }
    } else if (item.type === "drops") {
      if (!objectiveFor("collect_drops")) {
        toast("Sunny drops sparkle here when you have an active daily adventure.");
      } else {
        await markObjective("collect_drops");
      }
    } else if (item.type === "bellspire") {
      if (objectiveFor("reach_sunset_ridge")) await markObjective("reach_sunset_ridge");
      else toast("The whole island glows from the Solstice Bellspire.");
    } else if (item.type === "supply") {
      const drop = PICKUPS.find((pickup) => pickup.zone === "loot_canyon"
        && !state.progress.unlocked_collectibles.includes(pickup.id));
      if (drop) {
        await pickUp(drop);
      } else {
        toast("This supply drop is empty. Check back when the next drop arrives.");
      }
    } else if (item.type === "portal") {
      const blocker = guardianBlockingPortal(state.position.zone, item.zone);
      if (blocker) {
        const gState = getGuardianState(blocker);
        gState.hitFlashUntil = performance.now() + 260;
        spawnBurst(blocker.x, blocker.y - 35, "#ff5a8a", 12);
        toast(isArabic() ? `اهزم حارس البوابة أولاً! اضغط F أو انقر لإطلاق شعاع العصا.` : `Defeat ${blocker.name} first! Press F or click to shoot.`, 2600);
        addFloatingText(blocker.x, blocker.y - 96, isArabic() ? "اهزم الحارس أولاً!" : "DEFEAT GUARDIAN FIRST!", "#ff9ec2");
        return;
      }
      await enterZone(item.zone);
    }
  };

  const startAdventure = async () => {
    if (!state.authenticated) {
      setStatus("Sign in to start a daily adventure and earn rewards.", true);
      return;
    }
    if (state.busy) return;
    state.busy = true;
    renderProgress();
    try {
      const key = globalThis.crypto?.randomUUID
        ? globalThis.crypto.randomUUID()
        : `sunny-${Date.now()}-${Math.random().toString(36).slice(2, 12)}`;
      const result = await api("/api/games/sunny-island/start", "POST", { idempotency_key: key });
      updateSession(result.session);
      state.adventure.attempts_remaining_today = result.attempts_remaining_today;
      state.adventure.reward_amount_coins = result.reward_amount_coins;
      state.beaconActivations.clear();
      state.defeatedGuardians.clear();
      state.guardianStates = {};
      renderProgress();
      setStatus("Your three daily objectives are ready. Adventure on!");
      toast("Daily Adventure started!", 2500);
      if (result.session.objectives.some((objective) => objective.zone === state.position.zone)) {
        scheduleProgressSync(true);
      }
    } catch (error) {
      setStatus(error instanceof Error ? error.message : "A daily adventure could not be started.", true);
    } finally {
      state.busy = false;
      renderProgress();
    }
  };

  const completeAdventure = async () => {
    if (!state.session || state.busy) return;
    const previousLevel = state.progress.level;
    state.busy = true;
    renderProgress();
    try {
      const nonce = state.session.action_nonce;
      if (typeof nonce !== "string" || nonce === "") {
        throw new Error("The Sunny Island completion nonce is unavailable.");
      }
      const requestKey = globalThis.crypto?.randomUUID
        ? globalThis.crypto.randomUUID()
        : `sunny-complete-${Date.now()}-${Math.random().toString(36).slice(2, 12)}`;
      const result = await api("/api/games/sunny-island/complete", "POST", {
        session_id: state.session.id,
        request_key: requestKey,
        completion_nonce: nonce,
      });
      updateProgress(result.progress);
      updateSession(result.session);
      await refreshWallet();
      const levelText = result.progress.level > previousLevel
        ? ` Level up! You are now level ${result.progress.level}.`
        : "";
      toast(`Adventure complete! +${result.xp_awarded} XP · +${formatNumber(result.reward.amount)} Earned Coins.${levelText}`, 5200);
      setStatus(`Adventure complete! ${formatNumber(result.reward.amount)} Earned Coins added to your wallet.`);
      celebrateVictory();
      if (result.reward.amount > 0) spawnBurst(state.position.x, state.position.y, "#ffda62", 36);
      await loadStateAfterComplete(result);
    } catch (error) {
      setStatus(error instanceof Error ? error.message : "The adventure could not be completed.", true);
    } finally {
      state.busy = false;
      renderProgress();
    }
  };

  const loadStateAfterComplete = async (result) => {
    state.progress = result.progress;
    const refreshed = await api("/api/games/sunny-island/state");
    state.adventure = refreshed.state.adventure;
    state.session = null;
    renderProgress();
    renderMap();
  };

  const spawnBurst = (x, y, color, count = 10) => {
    for (let index = 0; index < count; index++) {
      const angle = Math.random() * Math.PI * 2;
      const speed = 25 + Math.random() * 110;
      state.particles.push({
        x,
        y,
        vx: Math.cos(angle) * speed,
        vy: Math.sin(angle) * speed - 35,
        size: 2 + Math.random() * 5,
        life: .55 + Math.random() * .45,
        color,
      });
    }
  };

  const addFloatingText = (x, y, text, color = "#fff") => {
    state.floatingTexts.push({ x, y, text, color, life: 1.15 });
  };

  const isSupplyDropActive = (now) => {
    const cycle = 52000;
    const activeWindow = 17000;
    return (now - state.startedAt) % cycle < activeWindow;
  };

  const drawRoundRect = (ctx, x, y, width, height, radius, fill, stroke = null) => {
    ctx.beginPath();
    ctx.roundRect(x, y, width, height, radius);
    ctx.fillStyle = fill;
    ctx.fill();
    if (stroke) {
      ctx.strokeStyle = stroke;
      ctx.lineWidth = 3;
      ctx.stroke();
    }
  };

  const drawPalm = (ctx, x, y, scale = 1, color = "#438c67") => {
    ctx.save();
    ctx.translate(x, y);
    ctx.scale(scale, scale);
    ctx.strokeStyle = "#986b4a";
    ctx.lineWidth = 16;
    ctx.lineCap = "round";
    ctx.beginPath();
    ctx.moveTo(0, 0);
    ctx.quadraticCurveTo(-16, -85, 10, -175);
    ctx.stroke();
    ctx.lineWidth = 11;
    ctx.strokeStyle = "#79583f";
    ctx.beginPath();
    ctx.moveTo(-2, -18);
    ctx.lineTo(-7, -31);
    ctx.stroke();
    const crownY = -176;
    for (let index = 0; index < 7; index++) {
      const angle = -Math.PI * .92 + (Math.PI * .84 / 6) * index;
      ctx.save();
      ctx.translate(8, crownY);
      ctx.rotate(angle);
      ctx.fillStyle = index % 2 ? color : "#69b77b";
      ctx.strokeStyle = "#327853";
      ctx.lineWidth = 3;
      ctx.beginPath();
      ctx.moveTo(0, 0);
      ctx.quadraticCurveTo(32, -40, 86, -15);
      ctx.quadraticCurveTo(48, 4, 0, 0);
      ctx.fill();
      ctx.stroke();
      ctx.restore();
    }
    ctx.fillStyle = "#f3bc61";
    ctx.beginPath();
    ctx.arc(8, crownY + 6, 13, 0, Math.PI * 2);
    ctx.fill();
    ctx.restore();
  };

  const drawStiltHut = (ctx, x, floor, scale = 1) => {
    ctx.save();
    ctx.translate(x, floor);
    ctx.scale(scale, scale);
    ctx.strokeStyle = "#79583f";
    ctx.lineWidth = 12;
    for (const postX of [-56, -18, 22, 57]) {
      ctx.beginPath();
      ctx.moveTo(postX, -22);
      ctx.lineTo(postX + (postX < 0 ? 5 : -5), 92);
      ctx.stroke();
    }
    drawRoundRect(ctx, -77, -103, 154, 79, 7, "#b57c53", "#704c3b");
    ctx.fillStyle = "#e7b46b";
    ctx.beginPath();
    ctx.moveTo(-92, -98);
    ctx.lineTo(-54, -153);
    ctx.lineTo(13, -150);
    ctx.lineTo(93, -96);
    ctx.closePath();
    ctx.fill();
    ctx.strokeStyle = "#76503b";
    ctx.lineWidth = 5;
    ctx.stroke();
    ctx.fillStyle = "#5b463e";
    ctx.fillRect(-57, -84, 37, 37);
    ctx.fillRect(22, -84, 37, 37);
    ctx.strokeStyle = "#ffda88";
    ctx.lineWidth = 3;
    ctx.strokeRect(-57, -84, 37, 37);
    ctx.strokeRect(22, -84, 37, 37);
    ctx.fillStyle = "#8b5a42";
    ctx.fillRect(-9, -73, 21, 49);
    ctx.fillStyle = "#7de8e9";
    ctx.fillRect(-49, -77, 20, 23);
    ctx.fillRect(30, -77, 20, 23);
    ctx.fillStyle = "#d8a162";
    ctx.fillRect(-87, -25, 174, 9);
    ctx.restore();
  };

  const drawBoat = (ctx, x, y, scale, color) => {
    ctx.save();
    ctx.translate(x, y + Math.sin(x * .03 + y) * 2);
    ctx.scale(scale, scale);
    ctx.fillStyle = color;
    ctx.strokeStyle = "#285d70";
    ctx.lineWidth = 4;
    ctx.beginPath();
    ctx.moveTo(-65, -7);
    ctx.lineTo(64, -7);
    ctx.lineTo(43, 19);
    ctx.quadraticCurveTo(0, 32, -43, 19);
    ctx.closePath();
    ctx.fill();
    ctx.stroke();
    ctx.fillStyle = "#f4e3b2";
    ctx.beginPath();
    ctx.moveTo(-3, -8);
    ctx.lineTo(27, -8);
    ctx.lineTo(11, -44);
    ctx.closePath();
    ctx.fill();
    ctx.strokeStyle = "#795a43";
    ctx.lineWidth = 3;
    ctx.beginPath();
    ctx.moveTo(0, -48);
    ctx.lineTo(0, -7);
    ctx.stroke();
    ctx.restore();
  };

  const drawCargoContainer = (ctx, x, y, width, height, color) => {
    ctx.fillStyle = color;
    ctx.strokeStyle = "#344d5c";
    ctx.lineWidth = 4;
    ctx.fillRect(x, y, width, height);
    ctx.strokeRect(x, y, width, height);
    ctx.strokeStyle = "rgb(255 255 255 / 27%)";
    ctx.lineWidth = 3;
    for (let ribX = x + 12; ribX < x + width; ribX += 19) {
      ctx.beginPath();
      ctx.moveTo(ribX, y + 5);
      ctx.lineTo(ribX, y + height - 5);
      ctx.stroke();
    }
    ctx.fillStyle = "rgb(255 255 255 / 22%)";
    ctx.fillRect(x + 7, y + 7, width - 14, 5);
  };

  const drawHazardBarrel = (ctx, x, y, color = "#e29642") => {
    ctx.save();
    ctx.translate(x, y);
    drawRoundRect(ctx, -15, -27, 30, 52, 7, color, "#684b3e");
    ctx.fillStyle = "#313c43";
    ctx.fillRect(-13, -8, 26, 10);
    ctx.strokeStyle = "#f7e5a5";
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.moveTo(-10, -5);
    ctx.lineTo(10, -5);
    ctx.stroke();
    ctx.restore();
  };

  const drawCrystalCover = (ctx, x, y) => {
    ctx.fillStyle = "rgb(105 218 237 / 52%)";
    ctx.strokeStyle = "#4aafc9";
    ctx.lineWidth = 4;
    ctx.beginPath();
    ctx.moveTo(x - 64, y + 20);
    ctx.lineTo(x - 54, y - 31);
    ctx.lineTo(x - 30, y - 22);
    ctx.lineTo(x - 15, y - 52);
    ctx.lineTo(x + 6, y - 28);
    ctx.lineTo(x + 31, y - 44);
    ctx.lineTo(x + 56, y - 22);
    ctx.lineTo(x + 64, y + 20);
    ctx.closePath();
    ctx.fill();
    ctx.stroke();
    ctx.strokeStyle = "rgb(255 255 255 / 78%)";
    ctx.lineWidth = 3;
    ctx.beginPath();
    ctx.moveTo(x - 14, y - 41);
    ctx.lineTo(x - 4, y + 8);
    ctx.lineTo(x + 23, y - 24);
    ctx.stroke();
  };

  const drawWatchtower = (ctx, x, y) => {
    ctx.fillStyle = "#80614b";
    ctx.fillRect(x - 9, y - 7, 18, 110);
    ctx.fillStyle = "#d5b886";
    ctx.strokeStyle = "#795c48";
    ctx.lineWidth = 4;
    ctx.fillRect(x - 43, y - 70, 86, 62);
    ctx.strokeRect(x - 43, y - 70, 86, 62);
    ctx.fillStyle = "#405c61";
    ctx.fillRect(x - 25, y - 55, 16, 22);
    ctx.fillRect(x + 9, y - 55, 16, 22);
    ctx.fillStyle = "#a36b4c";
    ctx.beginPath();
    ctx.moveTo(x - 53, y - 70);
    ctx.lineTo(x, y - 105);
    ctx.lineTo(x + 53, y - 70);
    ctx.closePath();
    ctx.fill();
    ctx.strokeStyle = "#684e40";
    ctx.lineWidth = 4;
    ctx.stroke();
  };

  // === POLISHED 3D PARALLAX & SEAMLESS VOLUMETRIC WORLD ===
  const parallaxShift = (wx, depthFactor, viewCenterX) =>
    (viewCenterX - wx) * depthFactor;

  const draw3DBlock = (ctx, x, y, w, h, depthX, depthY, palette, viewCenterX) => {
    const shiftX = parallaxShift(x + w * 0.5, 0.04, viewCenterX);
    const dx = depthX + shiftX;
    const dy = -Math.abs(depthY);

    ctx.save();
    ctx.lineJoin = "round";
    ctx.strokeStyle = palette.stroke || "rgb(34 28 50 / 28%)";
    ctx.lineWidth = 1.4;

    ctx.fillStyle = "rgb(22 18 38 / 14%)";
    ctx.fillRect(x - 4, y + h - 3, w + 10, 8);

    if (dx >= 0) {
      ctx.fillStyle = palette.side;
      ctx.beginPath();
      ctx.moveTo(x + w, y);
      ctx.lineTo(x + w + dx, y + dy);
      ctx.lineTo(x + w + dx, y + h + dy);
      ctx.lineTo(x + w, y + h);
      ctx.closePath();
      ctx.fill();
      ctx.stroke();
    } else {
      ctx.fillStyle = palette.side;
      ctx.beginPath();
      ctx.moveTo(x, y);
      ctx.lineTo(x + dx, y + dy);
      ctx.lineTo(x + dx, y + h + dy);
      ctx.lineTo(x, y + h);
      ctx.closePath();
      ctx.fill();
      ctx.stroke();
    }

    ctx.fillStyle = palette.top;
    ctx.beginPath();
    ctx.moveTo(x, y);
    ctx.lineTo(x + dx, y + dy);
    ctx.lineTo(x + w + dx, y + dy);
    ctx.lineTo(x + w, y);
    ctx.closePath();
    ctx.fill();
    ctx.stroke();

    ctx.fillStyle = palette.front;
    ctx.fillRect(x, y, w, h);
    ctx.strokeRect(x, y, w, h);
    ctx.restore();
  };

  const draw3DRotatingCrystal = (ctx, x, y, radius, height, time, speed, palette, viewCenterX) => {
    const px = x + parallaxShift(x, 0.035, viewCenterX);
    const py = y + Math.sin(time * 2.3 + x * 0.01) * 6;
    const rot = time * speed;
    const tilt = 0.34;

    const verts = [
      { x: 0, y: -height, z: 0 },
      { x: 0, y: height, z: 0 },
    ];
    for (let i = 0; i < 4; i++) {
      const a = rot + (i * Math.PI) / 2;
      const rx = Math.cos(a) * radius;
      const rz = Math.sin(a) * radius;
      verts.push({ x: rx, y: rz * Math.sin(tilt), z: rz * Math.cos(tilt) });
    }

    const faces = [
      [0, 2, 3], [0, 3, 4], [0, 4, 5], [0, 5, 2],
      [1, 3, 2], [1, 4, 3], [1, 5, 4], [1, 2, 5],
    ];

    const sorted = faces
      .map((f, idx) => ({
        f,
        idx,
        zAvg: (verts[f[0]].z + verts[f[1]].z + verts[f[2]].z) / 3,
      }))
      .sort((a, b) => a.zAvg - b.zAvg);

    ctx.save();
    ctx.translate(px, py);

    const glow = ctx.createRadialGradient(0, 0, 2, 0, 0, radius * 2.1);
    glow.addColorStop(0, palette.glow);
    glow.addColorStop(1, "rgb(255 255 255 / 0%)");
    ctx.fillStyle = glow;
    ctx.beginPath();
    ctx.arc(0, 0, radius * 2.1, 0, Math.PI * 2);
    ctx.fill();

    ctx.strokeStyle = palette.ring;
    ctx.lineWidth = 1.8;
    ctx.beginPath();
    ctx.ellipse(0, 0, radius * 1.45, radius * 0.42, Math.sin(time * 0.8) * 0.2, 0, Math.PI * 2);
    ctx.stroke();

    for (const item of sorted) {
      const [i0, i1, i2] = item.f;
      ctx.fillStyle = item.zAvg > 0
        ? (item.idx < 4 ? palette.light : palette.mid)
        : palette.dark;
      ctx.strokeStyle = "rgb(255 255 255 / 55%)";
      ctx.lineWidth = 1;
      ctx.beginPath();
      ctx.moveTo(verts[i0].x, verts[i0].y);
      ctx.lineTo(verts[i1].x, verts[i1].y);
      ctx.lineTo(verts[i2].x, verts[i2].y);
      ctx.closePath();
      ctx.fill();
      ctx.stroke();
    }
    ctx.restore();
  };

  const draw3DFloatingIsland = (ctx, x, y, rx, ry, rockDepth, time, palette, viewCenterX) => {
    const px = x + parallaxShift(x, 0.055, viewCenterX);
    const py = y + Math.sin(time * 1.5 + x * 0.008) * 5;
    const tipY = py + rockDepth;

    ctx.save();

    // 1. Cascading waterfall / energy stream dripping below the floating cliff
    const streamGrad = ctx.createLinearGradient(px, py + ry, px, tipY + 55);
    streamGrad.addColorStop(0, "rgb(255 250 220 / 55%)");
    streamGrad.addColorStop(1, "rgb(255 250 220 / 0%)");
    ctx.fillStyle = streamGrad;
    ctx.fillRect(px + rx * 0.22, py + ry * 0.5, 7, rockDepth + 48);

    // 2. Multi-faceted 3D rocky crag underside
    ctx.fillStyle = palette.rockLeft;
    ctx.beginPath();
    ctx.moveTo(px - rx * 0.94, py + ry * 0.25);
    ctx.lineTo(px - rx * 0.35, py + ry * 0.95);
    ctx.lineTo(px - rx * 0.12, tipY * 0.98 + py * 0.02);
    ctx.closePath();
    ctx.fill();

    ctx.fillStyle = palette.rockCenter;
    ctx.beginPath();
    ctx.moveTo(px - rx * 0.35, py + ry * 0.95);
    ctx.lineTo(px + rx * 0.34, py + ry * 0.92);
    ctx.lineTo(px + rx * 0.04, tipY);
    ctx.lineTo(px - rx * 0.12, tipY * 0.98 + py * 0.02);
    ctx.closePath();
    ctx.fill();

    ctx.fillStyle = palette.rockRight;
    ctx.beginPath();
    ctx.moveTo(px + rx * 0.34, py + ry * 0.92);
    ctx.lineTo(px + rx * 0.94, py + ry * 0.25);
    ctx.lineTo(px + rx * 0.04, tipY);
    ctx.closePath();
    ctx.fill();

    // 3. Thick 3D cliff rim wall
    ctx.fillStyle = palette.rockCenter;
    ctx.beginPath();
    ctx.ellipse(px, py + 7, rx, ry, 0, 0, Math.PI);
    ctx.fill();

    // 4. Lush 3D top plateau deck
    ctx.fillStyle = palette.topRim;
    ctx.beginPath();
    ctx.ellipse(px, py + 2, rx, ry, 0, 0, Math.PI * 2);
    ctx.fill();

    ctx.fillStyle = palette.topGrass;
    ctx.beginPath();
    ctx.ellipse(px, py - 1, rx * 0.93, ry * 0.84, 0, 0, Math.PI * 2);
    ctx.fill();

    // 5. Mini 3D Ruins / Foliage Cluster on top (instead of single needle)
    ctx.fillStyle = palette.spireDark;
    ctx.beginPath();
    ctx.arc(px - rx * 0.35, py - 5, 11, 0, Math.PI * 2);
    ctx.arc(px - rx * 0.2, py - 8, 9, 0, Math.PI * 2);
    ctx.fill();

    ctx.fillStyle = palette.spireLight;
    ctx.beginPath();
    ctx.arc(px - rx * 0.37, py - 7, 9, 0, Math.PI * 2);
    ctx.fill();

    // Small 3D floating crystal above the island
    const cryY = py - 20 + Math.sin(time * 3 + x) * 3;
    ctx.fillStyle = palette.spireLight;
    ctx.beginPath();
    ctx.moveTo(px + rx * 0.18, cryY - 12);
    ctx.lineTo(px + rx * 0.18 - 7, cryY);
    ctx.lineTo(px + rx * 0.18, cryY + 10);
    ctx.closePath();
    ctx.fill();

    ctx.fillStyle = palette.spireDark;
    ctx.beginPath();
    ctx.moveTo(px + rx * 0.18, cryY - 12);
    ctx.lineTo(px + rx * 0.18 + 7, cryY);
    ctx.lineTo(px + rx * 0.18, cryY + 10);
    ctx.closePath();
    ctx.fill();

    ctx.restore();
  };

  const draw3DMountainRange = (ctx, peaks, baseY, litColor, shadowColor, ridgeColor, depthFactor, viewCenterX) => {
    ctx.save();
    ctx.lineJoin = "round";

    // Continuous mountain massif base extending deep below foreground terrain so bases NEVER float
    const deepBottom = baseY + 180;
    ctx.fillStyle = shadowColor;
    ctx.beginPath();
    ctx.moveTo(-120, deepBottom);
    ctx.lineTo(-120, baseY - 35);
    for (const [px, peakY, halfWidth] of peaks) {
      const shift = parallaxShift(px, depthFactor, viewCenterX);
      ctx.lineTo(px - halfWidth * 0.72 + shift * 0.4, baseY - 45);
      ctx.lineTo(px + shift, peakY);
      ctx.lineTo(px + halfWidth * 0.72 + shift * 0.4, baseY - 45);
    }
    ctx.lineTo(WORLD.width + 120, baseY - 35);
    ctx.lineTo(WORLD.width + 120, deepBottom);
    ctx.closePath();
    ctx.fill();

    // Sunlit & shadowed 3D faceted mountain peaks
    for (const [px, peakY, halfWidth] of peaks) {
      const shift = parallaxShift(px, depthFactor, viewCenterX);
      const apexX = px + shift;
      const ridgeFootX = px + halfWidth * 0.1 + shift * 0.45;

      // Sunlit left 3D slope
      ctx.fillStyle = litColor;
      ctx.beginPath();
      ctx.moveTo(px - halfWidth, deepBottom);
      ctx.lineTo(apexX, peakY);
      ctx.lineTo(ridgeFootX, deepBottom);
      ctx.closePath();
      ctx.fill();

      // Shadowed right 3D slope
      ctx.fillStyle = shadowColor;
      ctx.beginPath();
      ctx.moveTo(apexX, peakY);
      ctx.lineTo(px + halfWidth, deepBottom);
      ctx.lineTo(ridgeFootX, deepBottom);
      ctx.closePath();
      ctx.fill();

      // Crisp 3D ridge highlight
      ctx.strokeStyle = ridgeColor;
      ctx.lineWidth = 2;
      ctx.beginPath();
      ctx.moveTo(apexX, peakY);
      ctx.lineTo(ridgeFootX, baseY + 20);
      ctx.stroke();
    }

    // Soft atmospheric valley mist gradient blending the lower slopes
    const mist = ctx.createLinearGradient(0, baseY - 60, 0, baseY + 80);
    mist.addColorStop(0, "rgb(255 244 224 / 0%)");
    mist.addColorStop(1, "rgb(255 236 208 / 26%)");
    ctx.fillStyle = mist;
    ctx.fillRect(0, baseY - 60, WORLD.width, 140);

    ctx.restore();
  };

  const draw3DPerspectiveGrid = (ctx, startY, endY, strokeColor, viewCenterX) => {
    ctx.save();
    // Soft curved 3D terrain contour ribbons instead of harsh straight spiderweb lines
    ctx.strokeStyle = strokeColor;
    ctx.lineWidth = 2;
    const bands = 4;
    for (let b = 1; b <= bands; b++) {
      const t = b / (bands + 1);
      const y = startY + (endY - startY) * t;
      const waveOffset = parallaxShift(800, 0.03 * b, viewCenterX);
      ctx.beginPath();
      ctx.moveTo(0, y);
      ctx.bezierCurveTo(
        420 + waveOffset,
        y - 18,
        1180 + waveOffset,
        y + 18,
        WORLD.width,
        y - 6
      );
      ctx.stroke();
    }
    ctx.restore();
  };

  const drawSkyAtmosphere = (ctx, time, cloudColor, birdColor, viewCenterX) => {
    ctx.save();
    for (let i = 0; i < 6; i++) {
      const speed = 10 + (i % 3) * 5;
      const cx = ((i * 300 + time * speed) % (WORLD.width + 340)) - 170 + parallaxShift(i * 300, 0.05, viewCenterX);
      const cy = 58 + (i % 3) * 34 + Math.sin(time * 0.7 + i) * 4;
      const scale = 0.7 + (i % 3) * 0.2;
      ctx.fillStyle = cloudColor;
      ctx.beginPath();
      ctx.arc(cx, cy, 28 * scale, 0, Math.PI * 2);
      ctx.arc(cx + 24 * scale, cy - 8 * scale, 34 * scale, 0, Math.PI * 2);
      ctx.arc(cx + 52 * scale, cy - 3 * scale, 26 * scale, 0, Math.PI * 2);
      ctx.arc(cx + 74 * scale, cy + 5 * scale, 20 * scale, 0, Math.PI * 2);
      ctx.fill();
    }

    ctx.strokeStyle = birdColor;
    ctx.lineWidth = 2;
    ctx.lineCap = "round";
    for (let b = 0; b < 5; b++) {
      const bx = ((b * 340 + time * (24 + b * 4)) % (WORLD.width + 200)) - 100;
      const by = 85 + (b % 3) * 32 + Math.sin(time * 1.8 + b * 2) * 10;
      const flap = Math.sin(time * 7.5 + b * 1.7) * 6;
      ctx.beginPath();
      ctx.moveTo(bx - 10, by - flap);
      ctx.quadraticCurveTo(bx - 4, by - 3, bx, by);
      ctx.quadraticCurveTo(bx + 4, by - 3, bx + 10, by - flap);
      ctx.stroke();
    }
    ctx.restore();
  };

  const drawBackground = (ctx, zone, time) => {
    const cameraX = Math.max(0, Math.min(WORLD.width - WORLD.viewWidth, state.position.x - WORLD.viewWidth / 2));
    const viewCenterX = cameraX + WORLD.viewWidth / 2;

    const sky = {
      neon_pier: ["#3aa8cc", "#b8f1e7"],
      palm_grove: ["#76c28e", "#e4eda6"],
      loot_canyon: ["#7fa3ad", "#e6b878"],
      sunset_ridge: ["#e37f7b", "#f9d68b"],
    }[zone] || ["#78d8ea", "#d9f8f2"];
    const gradient = ctx.createLinearGradient(0, 0, 0, WORLD.height);
    gradient.addColorStop(0, sky[0]);
    gradient.addColorStop(1, sky[1]);
    ctx.fillStyle = gradient;
    ctx.fillRect(0, 0, WORLD.width, WORLD.height);

    if (zone === "neon_pier") {
      const oceanGrad = ctx.createLinearGradient(0, 0, 0, 475);
      oceanGrad.addColorStop(0, "#2b94b8");
      oceanGrad.addColorStop(1, "#52d4df");
      ctx.fillStyle = oceanGrad;
      ctx.fillRect(0, 0, WORLD.width, 475);

      for (let index = 0; index < 12; index++) {
        const y = 40 + index * 34;
        ctx.globalAlpha = 0.25 + Math.sin(time * 2.5 + index) * 0.08;
        ctx.strokeStyle = index % 2 ? "#eafffc" : "#a8f9ff";
        ctx.lineWidth = 2.5;
        ctx.beginPath();
        for (let x = 0; x <= WORLD.width; x += 42) {
          const waveY = y + Math.sin(x * 0.009 + time * 1.9 + index) * 5;
          if (x === 0) ctx.moveTo(x, waveY);
          else ctx.lineTo(x, waveY);
        }
        ctx.stroke();
      }
      ctx.globalAlpha = 1;

      drawSkyAtmosphere(ctx, time, "rgb(255 255 255 / 24%)", "rgb(22 68 78 / 45%)", viewCenterX);

      const bayIslandPalette = {
        rockLeft: "#276972",
        rockCenter: "#1d525a",
        rockRight: "#153d44",
        topRim: "#9ee8c8",
        topGrass: "#46a883",
        spireLight: "#80f8ff",
        spireDark: "#38a3b8",
      };
      draw3DFloatingIsland(ctx, 430, 165, 92, 22, 54, time, bayIslandPalette, viewCenterX);
      draw3DFloatingIsland(ctx, 1340, 155, 105, 24, 60, time + 1.4, bayIslandPalette, viewCenterX);

      draw3DRotatingCrystal(
        ctx,
        860,
        165,
        20,
        32,
        time,
        1.5,
        {
          glow: "rgb(128 248 255 / 45%)",
          ring: "rgb(255 120 195 / 80%)",
          light: "#d4fcff",
          mid: "#6beeff",
          dark: "#269cb8",
        },
        viewCenterX
      );

      for (const [bx, by, bs, bc, phase] of [
        [450, 325, 0.84, "#e47859", 0],
        [930, 295, 1.05, "#315d8a", 1.7],
        [1450, 355, 0.7, "#f2c96f", 3.1],
      ]) {
        ctx.save();
        ctx.translate(bx, by + Math.sin(time * 2.3 + phase) * 4);
        ctx.rotate(Math.sin(time * 1.9 + phase) * 0.03);
        drawBoat(ctx, 0, 0, bs, bc);
        ctx.restore();
      }

      drawStiltHut(ctx, 245, 455, 0.9);
      drawStiltHut(ctx, 1135, 420, 1.05);
      drawStiltHut(ctx, 690, 360, 0.68);

      ctx.fillStyle = "#b57252";
      ctx.fillRect(0, 475, WORLD.width, 190);
      draw3DPerspectiveGrid(ctx, 475, 665, "rgb(255 215 162 / 32%)", viewCenterX);
      ctx.fillStyle = "#7a4933";
      ctx.fillRect(0, 665, WORLD.width, 20);

      for (const postX of [120, 330, 550, 770, 990, 1210, 1430, 1570]) {
        draw3DBlock(
          ctx,
          postX - 7,
          685,
          14,
          68,
          6,
          6,
          { top: "#8c624e", front: "#684a3d", side: "#4e362c" },
          viewCenterX
        );
      }

      ctx.fillStyle = "#dfc47f";
      ctx.fillRect(0, 760, WORLD.width, 240);
      draw3DPerspectiveGrid(ctx, 760, 1000, "rgb(184 146 74 / 24%)", viewCenterX);

      for (const palmX of [65, 380, 745, 1115, 1510]) {
        ctx.save();
        ctx.translate(palmX, 900);
        ctx.rotate(Math.sin(time * 2.1 + palmX * 0.01) * 0.03);
        drawPalm(ctx, 0, 0, 0.54, "#398b68");
        ctx.restore();
      }

      ctx.save();
      ctx.setLineDash([17, 15]);
      ctx.lineDashOffset = -time * 28;
      ctx.strokeStyle = "rgb(120 251 255 / " + (45 + Math.sin(time * 2.4) * 12) + "%)";
      ctx.lineWidth = 7;
      ctx.beginPath();
      ctx.ellipse(800, 510, 760, 455, 0, 0, Math.PI * 2);
      ctx.stroke();
      ctx.restore();
    } else if (zone === "palm_grove") {
      ctx.fillStyle = "#74ba72";
      ctx.fillRect(0, 0, WORLD.width, WORLD.height);
      drawSkyAtmosphere(ctx, time, "rgb(255 255 240 / 24%)", "rgb(45 84 52 / 44%)", viewCenterX);

      draw3DMountainRange(
        ctx,
        [
          [160, 140, 220],
          [480, 130, 240],
          [1120, 130, 240],
          [1460, 140, 220],
        ],
        245,
        "#8fd17d",
        "#5ca163",
        "#b6eb9b",
        0.04,
        viewCenterX
      );

      draw3DFloatingIsland(
        ctx,
        200,
        130,
        82,
        20,
        48,
        time + 0.8,
        {
          rockLeft: "#7c6b53",
          rockCenter: "#61533f",
          rockRight: "#493e2e",
          topRim: "#b5e68e",
          topGrass: "#67b868",
          spireLight: "#ffe680",
          spireDark: "#c79a3b",
        },
        viewCenterX
      );

      ctx.fillStyle = "#d1bb86";
      ctx.beginPath();
      ctx.moveTo(0, 690);
      ctx.bezierCurveTo(330, 560, 470, 840, 760, 635);
      ctx.bezierCurveTo(1010, 470, 1270, 730, WORLD.width, 520);
      ctx.lineTo(WORLD.width, 815);
      ctx.bezierCurveTo(1260, 930, 1010, 730, 760, 870);
      ctx.bezierCurveTo(440, 1010, 250, 785, 0, 940);
      ctx.closePath();
      ctx.fill();
      draw3DPerspectiveGrid(ctx, 480, 980, "rgb(96 124 72 / 16%)", viewCenterX);

      draw3DBlock(
        ctx,
        485,
        250,
        630,
        195,
        18,
        14,
        { top: "#dfd0ad", front: "#6b7760", side: "#525c49" },
        viewCenterX
      );

      draw3DBlock(
        ctx,
        465,
        235,
        670,
        20,
        22,
        14,
        { top: "#ede0c2", front: "#c4ae84", side: "#967d58" },
        viewCenterX
      );
      ctx.fillStyle = "#b39a73";
      ctx.fillRect(500, 425, 600, 20);

      const colPalette = { top: "#efe3c6", front: "#d6c29a", side: "#a88d69" };
      for (const columnX of [540, 624, 716, 810, 904, 990, 1065]) {
        draw3DBlock(ctx, columnX, 278, 17, 144, 7, 6, colPalette, viewCenterX);
      }

      for (const [idx, archX] of [670, 855, 1018].entries()) {
        draw3DRotatingCrystal(
          ctx,
          archX,
          352,
          13,
          22,
          time + idx * 1.1,
          1.7,
          {
            glow: "rgb(142 255 214 / 45%)",
            ring: "rgb(255 224 120 / 85%)",
            light: "#d9ffef",
            mid: "#7cf5c4",
            dark: "#369c77",
          },
          viewCenterX
        );
      }

      drawWatchtower(ctx, 1330, 390);

      for (const crateX of [320, 1260]) {
        draw3DBlock(
          ctx,
          crateX - 28,
          625,
          56,
          48,
          12,
          10,
          { top: "#d6a468", front: "#a97845", side: "#7d5630" },
          viewCenterX
        );
      }

      for (let index = 0; index < 6; index++) {
        const px = 105 + index * 265;
        const py = 910 - (index % 3) * 45;
        ctx.save();
        ctx.translate(px, py);
        ctx.rotate(Math.sin(time * 2.2 + index) * 0.035);
        drawPalm(ctx, 0, 0, 0.43 + (index % 2) * 0.09, "#478656");
        ctx.restore();
      }
    } else if (zone === "loot_canyon") {
      ctx.fillStyle = "#8a9b91";
      ctx.fillRect(0, 0, WORLD.width, WORLD.height);
      drawSkyAtmosphere(ctx, time, "rgb(255 248 232 / 22%)", "rgb(52 68 68 / 45%)", viewCenterX);

      draw3DMountainRange(
        ctx,
        [
          [170, 95, 220],
          [800, 82, 310],
          [1430, 95, 220],
        ],
        195,
        "#94a69e",
        "#617370",
        "#c2d1c4",
        0.035,
        viewCenterX
      );

      ctx.fillStyle = "#b8aa87";
      ctx.fillRect(0, 715, WORLD.width, 285);
      draw3DPerspectiveGrid(ctx, 715, 1000, "rgb(65 79 75 / 24%)", viewCenterX);

      draw3DBlock(
        ctx,
        260,
        175,
        1080,
        265,
        22,
        16,
        { top: "#879991", front: "#586a68", side: "#3e5052" },
        viewCenterX
      );

      ctx.fillStyle = "#71827a";
      ctx.fillRect(285, 202, 1030, 226);
      ctx.fillStyle = "#3e5052";
      for (const [bx, bw] of [[320, 170], [548, 170], [776, 170], [1004, 270]]) {
        ctx.fillRect(bx, 250, bw, 150);
      }
      ctx.fillStyle = "#31484d";
      ctx.fillRect(285, 422, 1030, 16);

      const trolleyX = 800 + Math.sin(time * 0.7) * 420;
      draw3DBlock(
        ctx,
        trolleyX - 24,
        168,
        48,
        18,
        10,
        8,
        { top: "#ffd875", front: "#f3b844", side: "#b88224" },
        viewCenterX
      );

      ctx.fillStyle = "#edf0d0";
      ctx.font = "900 22px sans-serif";
      ctx.textAlign = "center";
      ctx.fillText("SKYCRATE WORKS", 800, 234);

      draw3DBlock(ctx, 74, 536, 250, 145, 18, 14, { top: "#eb8b73", front: "#d46f55", side: "#9c4935" }, viewCenterX);
      draw3DBlock(ctx, 368, 592, 255, 110, 16, 12, { top: "#6db0ba", front: "#4f8e98", side: "#34646c" }, viewCenterX);
      draw3DBlock(ctx, 1025, 535, 250, 145, 18, 14, { top: "#ecc474", front: "#d7aa55", side: "#9e7731" }, viewCenterX);
      draw3DBlock(ctx, 1320, 590, 200, 115, 16, 12, { top: "#829ad1", front: "#637bb0", side: "#425580" }, viewCenterX);

      draw3DRotatingCrystal(
        ctx,
        835,
        488,
        16,
        26,
        time,
        1.8,
        {
          glow: "rgb(210 140 255 / 45%)",
          ring: "rgb(255 79 163 / 85%)",
          light: "#f6e3ff",
          mid: "#c97aff",
          dark: "#753aa6",
        },
        viewCenterX
      );

      drawHazardBarrel(ctx, 220, 720);
      drawHazardBarrel(ctx, 670, 695, "#cc6550");
      drawHazardBarrel(ctx, 930, 735);
      drawHazardBarrel(ctx, 1450, 750, "#cc6550");
      drawCrystalCover(ctx, 730, 585);
      drawCrystalCover(ctx, 935, 620);
    } else {
      // Sunset Ridge / Solstice Bellspire — Seamless 3D Mountain Ranges & Floating Sky Islands
      const sunsetGrad = ctx.createLinearGradient(0, 0, 0, WORLD.height);
      sunsetGrad.addColorStop(0, "#df797c");
      sunsetGrad.addColorStop(0.52, "#f2ad77");
      sunsetGrad.addColorStop(1, "#f7d48b");
      ctx.fillStyle = sunsetGrad;
      ctx.fillRect(0, 0, WORLD.width, WORLD.height);

      const sunX = 1295 + parallaxShift(1295, 0.02, viewCenterX);
      const sunY = 148;

      ctx.save();
      ctx.translate(sunX, sunY);
      ctx.rotate(time * 0.08);
      for (let r = 0; r < 12; r++) {
        ctx.rotate((Math.PI * 2) / 12);
        const rayGrad = ctx.createLinearGradient(0, 0, 0, 680);
        rayGrad.addColorStop(0, "rgb(255 244 185 / 24%)");
        rayGrad.addColorStop(1, "rgb(255 210 130 / 0%)");
        ctx.fillStyle = rayGrad;
        ctx.beginPath();
        ctx.moveTo(-16, 0);
        ctx.lineTo(16, 0);
        ctx.lineTo(66, 680);
        ctx.lineTo(-66, 680);
        ctx.closePath();
        ctx.fill();
      }
      ctx.restore();

      const pulseRadius = 115 + Math.sin(time * 1.8) * 10;
      const sun = ctx.createRadialGradient(sunX, sunY, 15, sunX, sunY, pulseRadius);
      sun.addColorStop(0, "rgb(255 252 210 / 98%)");
      sun.addColorStop(0.45, "rgb(255 224 140 / 55%)");
      sun.addColorStop(1, "rgb(255 210 111 / 0%)");
      ctx.fillStyle = sun;
      ctx.beginPath();
      ctx.arc(sunX, sunY, pulseRadius, 0, Math.PI * 2);
      ctx.fill();

      ctx.save();
      ctx.translate(sunX, sunY);
      ctx.strokeStyle = "rgb(255 245 190 / 48%)";
      ctx.lineWidth = 2.2;
      ctx.beginPath();
      ctx.ellipse(0, 0, 108, 28, -0.22 + Math.sin(time * 0.5) * 0.05, 0, Math.PI * 2);
      ctx.stroke();
      ctx.restore();

      drawSkyAtmosphere(ctx, time, "rgb(255 236 218 / 26%)", "rgb(98 54 68 / 46%)", viewCenterX);

      // 1. Far 3D Low-Poly Mountains (deep base reaches behind near mountains)
      draw3DMountainRange(
        ctx,
        [
          [220, 245, 310],
          [700, 195, 350],
          [1240, 235, 325],
        ],
        580,
        "#cf887e",
        "#9f6166",
        "#f7be9c",
        0.045,
        viewCenterX
      );

      // 2. Two detailed 3D Floating Sky Islands with foliage, crystal & waterfall
      const sunsetIslandPalette = {
        rockLeft: "#9c626c",
        rockCenter: "#784956",
        rockRight: "#593441",
        topRim: "#f7ce88",
        topGrass: "#e39c6b",
        spireLight: "#fff3b8",
        spireDark: "#e09f48",
      };
      draw3DFloatingIsland(ctx, 455, 165, 86, 22, 54, time, sunsetIslandPalette, viewCenterX);
      draw3DFloatingIsland(ctx, 975, 155, 92, 24, 58, time + 1.9, sunsetIslandPalette, viewCenterX);

      // 3. Drifting valley mist between far and near mountains
      for (let m = 0; m < 4; m++) {
        const mx = ((m * 430 + time * (22 + m * 6)) % (WORLD.width + 500)) - 250;
        const my = 455 + m * 36 + Math.sin(time * 1.1 + m) * 6;
        const mistGrad = ctx.createLinearGradient(mx - 180, my, mx + 180, my);
        mistGrad.addColorStop(0, "rgb(255 236 214 / 0%)");
        mistGrad.addColorStop(0.5, "rgb(255 236 214 / 28%)");
        mistGrad.addColorStop(1, "rgb(255 236 214 / 0%)");
        ctx.fillStyle = mistGrad;
        ctx.beginPath();
        ctx.ellipse(mx, my, 200, 22, 0, 0, Math.PI * 2);
        ctx.fill();
      }

      // 4. Near 3D Low-Poly Mountains (deep base sinks smoothly BEHIND the golden terrain!)
      draw3DMountainRange(
        ctx,
        [
          [285, 465, 330],
          [735, 445, 350],
          [1240, 465, 335],
        ],
        750,
        "#9e737c",
        "#73505c",
        "#d49b96",
        0.025,
        viewCenterX
      );

      // 5. Golden foreground ridge terrain drawn OVER mountain bases so zero gap ever appears
      ctx.fillStyle = "#d7b979";
      ctx.beginPath();
      ctx.moveTo(0, 755);
      ctx.quadraticCurveTo(360, 665, 700, 795);
      ctx.quadraticCurveTo(1090, 935, 1600, 750);
      ctx.lineTo(1600, 1000);
      ctx.lineTo(0, 1000);
      ctx.closePath();
      ctx.fill();

      // Sunlit rim highlight along the golden hill crest
      ctx.strokeStyle = "rgb(255 243 196 / 65%)";
      ctx.lineWidth = 4;
      ctx.beginPath();
      ctx.moveTo(0, 755);
      ctx.quadraticCurveTo(360, 665, 700, 795);
      ctx.quadraticCurveTo(1090, 935, 1600, 750);
      ctx.stroke();

      draw3DPerspectiveGrid(ctx, 735, 1000, "rgb(168 124 58 / 22%)", viewCenterX);

      for (let p = 0; p < 20; p++) {
        const px = ((p * 89 + time * (34 + (p % 4) * 9)) % (WORLD.width + 80)) - 40;
        const py = ((p * 67 + time * (16 + (p % 3) * 6) + Math.sin(time * 2 + p) * 18) % 860) + 40;
        ctx.save();
        ctx.translate(px, py);
        ctx.rotate(time * 1.8 + p);
        ctx.fillStyle = p % 2 === 0 ? "rgb(255 178 214 / 70%)" : "rgb(255 243 184 / 74%)";
        ctx.beginPath();
        ctx.ellipse(0, 0, 5.5, 2.8, 0, 0, Math.PI * 2);
        ctx.fill();
        ctx.restore();
      }
    }

    for (let index = 0; index < 22; index++) {
      const x = (index * 97 + time * (14 + (index % 6) * 4) + Math.sin(time * 1.4 + index) * 14) % WORLD.width;
      const y = 90 + ((index * 67 + Math.cos(time * 1.2 + index) * 20) % 740);
      const alpha = 0.25 + Math.abs(Math.sin(time * 2.2 + index)) * 0.42;
      ctx.fillStyle = index % 3 === 0 ? "rgb(255 154 208 / " + Math.round(alpha * 100) + "%)" : "rgb(255 255 240 / " + Math.round(alpha * 100) + "%)";
      ctx.beginPath();
      ctx.arc(x, y, 2 + (index % 3) * 1, 0, Math.PI * 2);
      ctx.fill();
    }
  };

  const drawBeacon = (ctx, beacon, time) => {
    const pulse = 1 + Math.sin(time * 3 + beacon.x) * .06;
    ctx.save();
    ctx.translate(beacon.x, beacon.y);
    ctx.scale(pulse, pulse);
    ctx.fillStyle = "#856d60";
    ctx.strokeStyle = "#5f554f";
    ctx.lineWidth = 4;
    ctx.beginPath();
    ctx.moveTo(-31, 4);
    ctx.lineTo(-24, -45);
    ctx.lineTo(23, -45);
    ctx.lineTo(31, 4);
    ctx.closePath();
    ctx.fill();
    ctx.stroke();
    ctx.fillStyle = "#f4cf67";
    ctx.strokeStyle = "#fff2ac";
    ctx.beginPath();
    ctx.arc(0, -50, 18 + Math.sin(time * 4) * 2, 0, Math.PI * 2);
    ctx.fill();
    ctx.stroke();
    ctx.restore();
  };

  const drawBoard = (ctx, time) => {
    const x = QUEST_BOARD.x;
    const y = QUEST_BOARD.y;
    ctx.fillStyle = "#80563c";
    ctx.fillRect(x - 9, y - 52, 18, 87);
    drawRoundRect(ctx, x - 48, y - 100, 96, 59, 9, "#efbb58", "#885736");
    ctx.fillStyle = "#fff5d5";
    ctx.font = "800 14px sans-serif";
    ctx.textAlign = "center";
    ctx.fillText("QUEST", x, y - 68);
    ctx.fillText("BOARD", x, y - 50);
    ctx.fillStyle = "#ed7954";
    ctx.beginPath();
    ctx.arc(x + 33, y - 89 + Math.sin(time * 3) * 3, 7, 0, Math.PI * 2);
    ctx.fill();
  };

  const drawBellspire = (ctx, time) => {
    const x = BELLSPIRE.x;
    const y = BELLSPIRE.y;
    const glow = ctx.createRadialGradient(x, y - 205, 18, x, y - 205, 195);
    glow.addColorStop(0, "rgb(255 238 160 / 27%)");
    glow.addColorStop(1, "rgb(255 238 160 / 0%)");
    ctx.fillStyle = glow;
    ctx.beginPath();
    ctx.arc(x, y - 205, 195, 0, Math.PI * 2);
    ctx.fill();
    drawRoundRect(ctx, x - 77, y - 223, 154, 233, 16, "#bd916b", "#745347");
    ctx.fillStyle = "#d6b283";
    ctx.fillRect(x - 60, y - 207, 16, 198);
    ctx.fillRect(x + 44, y - 207, 16, 198);
    ctx.fillStyle = "#80604e";
    ctx.fillRect(x - 82, y - 224, 164, 16);
    drawRoundRect(ctx, x - 69, y - 288, 138, 76, 13, "#c6a078", "#745347");
    ctx.fillStyle = "#87654f";
    ctx.fillRect(x - 77, y - 218, 154, 12);

    ctx.fillStyle = "#f4e3b4";
    ctx.strokeStyle = "#77513e";
    ctx.lineWidth = 7;
    ctx.beginPath();
    ctx.arc(x, y - 250, 34, 0, Math.PI * 2);
    ctx.fill();
    ctx.stroke();
    ctx.strokeStyle = "#a67a53";
    ctx.lineWidth = 2;
    for (let tick = 0; tick < 12; tick++) {
      const angle = tick * Math.PI / 6;
      ctx.beginPath();
      ctx.moveTo(x + Math.cos(angle) * 25, y - 250 + Math.sin(angle) * 25);
      ctx.lineTo(x + Math.cos(angle) * 29, y - 250 + Math.sin(angle) * 29);
      ctx.stroke();
    }
    ctx.strokeStyle = "#4e4850";
    ctx.lineWidth = 4;
    ctx.lineCap = "round";
    ctx.beginPath();
    ctx.moveTo(x, y - 250);
    ctx.lineTo(x + Math.sin(time * .16) * 15, y - 250 - Math.cos(time * .16) * 15);
    ctx.moveTo(x, y - 250);
    ctx.lineTo(x - 15, y - 250 + Math.cos(time * .08) * 15);
    ctx.stroke();
    ctx.fillStyle = "#e0bd78";
    ctx.beginPath();
    ctx.arc(x, y - 250, 5, 0, Math.PI * 2);
    ctx.fill();

    ctx.fillStyle = "#8b6653";
    ctx.beginPath();
    ctx.moveTo(x - 90, y - 286);
    ctx.lineTo(x, y - 350);
    ctx.lineTo(x + 90, y - 286);
    ctx.closePath();
    ctx.fill();
    ctx.strokeStyle = "#63483f";
    ctx.lineWidth = 5;
    ctx.stroke();
    ctx.fillStyle = "#e4c47f";
    ctx.beginPath();
    ctx.moveTo(x - 11, y - 337);
    ctx.lineTo(x, y - 366);
    ctx.lineTo(x + 11, y - 337);
    ctx.closePath();
    ctx.fill();
    drawRoundRect(ctx, x - 27, y - 77, 54, 87, 25, "#5c4a4b", "#735344");
    drawRoundRect(ctx, x - 13, y - 63, 26, 73, 13, "#332f43");
    ctx.fillStyle = "#d2ab72";
    for (const windowX of [x - 51, x + 51]) {
      ctx.beginPath();
      ctx.arc(windowX, y - 131, 12, Math.PI, 0);
      ctx.lineTo(windowX + 12, y - 106);
      ctx.lineTo(windowX - 12, y - 106);
      ctx.closePath();
      ctx.fill();
    }
    ctx.fillStyle = "#5e554d";
    ctx.fillRect(x - 83, y + 8, 166, 12);
  };

  const drawSupplyDrop = (ctx, time) => {
    if (!isSupplyDropActive(time * 1000)) return;
    const image = imageFor("sun_crate");
    const pulse = 1 + Math.sin(time * 5) * .05;
    ctx.save();
    ctx.translate(SUPPLY_DROP.x, SUPPLY_DROP.y);
    ctx.globalAlpha = .2 + Math.sin(time * 3) * .04;
    ctx.fillStyle = "#ffcf63";
    ctx.beginPath();
    ctx.moveTo(-15, -45);
    ctx.lineTo(-73, -260);
    ctx.lineTo(73, -260);
    ctx.lineTo(15, -45);
    ctx.closePath();
    ctx.fill();
    ctx.globalAlpha = 1;
    for (let index = 0; index < 5; index++) {
      const smokeX = Math.sin(time * 2.4 + index) * (12 + index * 6);
      const smokeY = -95 - ((time * 28 + index * 34) % 130);
      ctx.globalAlpha = .34 - index * .035;
      ctx.fillStyle = index % 2 ? "#79e8ec" : "#ffd46e";
      ctx.beginPath();
      ctx.arc(smokeX, smokeY, 15 + index * 3, 0, Math.PI * 2);
      ctx.fill();
    }
    ctx.globalAlpha = 1;
    ctx.scale(pulse, pulse);
    ctx.fillStyle = "rgb(255 230 117 / 28%)";
    ctx.beginPath();
    ctx.ellipse(0, 5, 65, 38, 0, 0, Math.PI * 2);
    ctx.fill();
    if (image?.complete && image.naturalWidth) {
      ctx.drawImage(image, -34, -60, 68, 68);
    } else {
      drawRoundRect(ctx, -30, -54, 60, 55, 9, "#e29c48", "#815938");
    }
    ctx.fillStyle = "#fff6c8";
    ctx.font = "800 12px sans-serif";
    ctx.textAlign = "center";
    ctx.fillText("AIR DROP", 0, 31);
    ctx.restore();
  };

  const drawPortal = (ctx, portal, time) => {
    const locked = Boolean(guardianBlockingPortal(state.position.zone, portal.zone));
    ctx.save();
    ctx.translate(portal.x, portal.y);

    // Grounded 3D stone pedestal & shadow under the portal arch
    ctx.fillStyle = "rgb(24 20 38 / 28%)";
    ctx.beginPath();
    ctx.ellipse(0, 12, 54, 16, 0, 0, Math.PI * 2);
    ctx.fill();

    drawRoundRect(ctx, -46, -2, 92, 16, 6, "#8c7a6b", "#3d3338");
    drawRoundRect(ctx, -40, -6, 80, 10, 4, "#cbb799", "#5c4d49");

    // Portal stone & energy arch
    ctx.strokeStyle = locked ? "#ff6b9d" : "#fff1bd";
    ctx.lineWidth = 8;
    ctx.beginPath();
    ctx.moveTo(-36, -4);
    ctx.lineTo(-36, -34);
    ctx.ellipse(0, -34, 36 + Math.sin(time * 2) * 1.5, 46, 0, Math.PI, Math.PI * 2);
    ctx.lineTo(36, -4);
    ctx.stroke();

    // Inner swirling portal energy
    ctx.fillStyle = locked ? "rgb(255 79 140 / 38%)" : "rgb(116 245 235 / 52%)";
    ctx.beginPath();
    ctx.moveTo(-27, -4);
    ctx.lineTo(-27, -34);
    ctx.ellipse(0, -34, 27, 36, 0, Math.PI, Math.PI * 2);
    ctx.lineTo(27, -4);
    ctx.closePath();
    ctx.fill();

    if (locked) {
      ctx.strokeStyle = "rgb(255 185 212 / 90%)";
      ctx.lineWidth = 2.4;
      ctx.setLineDash([6, 5]);
      ctx.lineDashOffset = -time * 18;
      ctx.beginPath();
      ctx.ellipse(0, -34, 30, 40, 0, Math.PI, Math.PI * 2);
      ctx.stroke();
      ctx.setLineDash([]);

      drawRoundRect(ctx, -30, -98, 60, 18, 9, "rgb(34 18 36 / 84%)", "#ff6b9d");
      ctx.fillStyle = "#ffd6e7";
      ctx.font = "800 10px sans-serif";
      ctx.textAlign = "center";
      ctx.fillText("SEALED", 0, -86);
    } else {
      drawRoundRect(ctx, -26, -98, 52, 18, 9, "rgb(18 44 48 / 84%)", "#80f8ff");
      ctx.fillStyle = "#eafffb";
      ctx.font = "800 10px sans-serif";
      ctx.textAlign = "center";
      ctx.fillText("OPEN", 0, -86);
    }
    ctx.restore();
  };

  const drawPickup = (ctx, item, time) => {
    const image = imageFor(item.id);
    const float = Math.sin(time * 3 + item.x * .02) * 5;
    ctx.save();
    ctx.translate(item.x, item.y - 20 + float);
    ctx.fillStyle = "rgb(255 238 139 / 24%)";
    ctx.beginPath();
    ctx.ellipse(0, 8, 35, 29, 0, 0, Math.PI * 2);
    ctx.fill();
    if (image?.complete && image.naturalWidth) {
      ctx.shadowColor = "rgb(47 91 103 / 28%)";
      ctx.shadowBlur = 9;
      ctx.drawImage(image, -25, -25, 50, 50);
      ctx.shadowBlur = 0;
    } else {
      ctx.fillStyle = colors.gold;
      ctx.beginPath();
      ctx.arc(0, 0, 18, 0, Math.PI * 2);
      ctx.fill();
      ctx.strokeStyle = "#fff7cd";
      ctx.lineWidth = 4;
      ctx.stroke();
    }
    ctx.restore();
  };

  const drawAmbientArt = (ctx, zone, time) => {
    for (const art of AMBIENT_ART) {
      if (art.zone !== zone) continue;
      const image = imageFor(art.imageId);
      if (!image?.complete || !image.naturalWidth) continue;
      const bob = Math.sin(time * 1.7 + art.x) * 4;
      ctx.globalAlpha = .78;
      ctx.drawImage(image, art.x - 26, art.y - 26 + bob, 52, 52);
      ctx.globalAlpha = 1;
    }
  };

  const drawWeaponCaches = (ctx, time) => {
    const caches = [
      { imageId: "neon_relic", x: 250, y: 610, tint: "#df9b53" },
      { imageId: "drift_token", x: 1390, y: 645, tint: "#77c8db" },
    ];
    for (const cache of caches) {
      ctx.save();
      ctx.translate(cache.x, cache.y + Math.sin(time * 2 + cache.x) * 2);
      ctx.fillStyle = "rgb(255 222 120 / 20%)";
      ctx.beginPath();
      ctx.ellipse(0, 16, 54, 25, 0, 0, Math.PI * 2);
      ctx.fill();
      drawRoundRect(ctx, -42, -7, 84, 36, 7, "#344b59", "#273d4c");
      ctx.fillStyle = cache.tint;
      ctx.fillRect(-32, -2, 64, 5);
      const image = imageFor(cache.imageId);
      if (image?.complete && image.naturalWidth) {
        ctx.shadowColor = cache.tint;
        ctx.shadowBlur = 12;
        ctx.drawImage(image, -39, -59, 78, 60);
        ctx.shadowBlur = 0;
      }
      ctx.fillStyle = "#fff4d0";
      ctx.font = "800 10px sans-serif";
      ctx.textAlign = "center";
      ctx.fillText("GEAR CACHE", 0, 44);
      ctx.restore();
    }
  };

  const drawWorldObjects = (ctx, time) => {
    const zone = state.position.zone;
    if (zone === QUEST_BOARD.zone) drawBoard(ctx, time);
    if (zone === BELLSPIRE.zone) drawBellspire(ctx, time);
    if (zone === SUPPLY_DROP.zone) drawSupplyDrop(ctx, time);
    if (zone === "loot_canyon") drawWeaponCaches(ctx, time);
    for (const beacon of BEACONS) {
      if (beacon.zone === zone) drawBeacon(ctx, beacon, time);
    }
    for (const portal of PORTALS[zone] || []) drawPortal(ctx, portal, time);
    for (const item of PICKUPS) {
      if (item.zone === zone && !state.progress.unlocked_collectibles.includes(item.id)) {
        drawPickup(ctx, item, time);
      }
    }
    drawAmbientArt(ctx, zone, time);
  };

  const playCombatTone = (type) => {
    try {
      const AudioCtx = window.AudioContext || window.webkitAudioContext;
      if (!AudioCtx) return;
      if (!playCombatTone.ctx) playCombatTone.ctx = new AudioCtx();
      const actx = playCombatTone.ctx;
      if (actx.state === "suspended") void actx.resume();
      const now = actx.currentTime;
      const osc = actx.createOscillator();
      const gain = actx.createGain();
      osc.connect(gain);
      gain.connect(actx.destination);
      if (type === "beam") {
        osc.type = "triangle";
        osc.frequency.setValueAtTime(920, now);
        osc.frequency.exponentialRampToValueAtTime(340, now + 0.14);
        gain.gain.setValueAtTime(0.08, now);
        gain.gain.exponentialRampToValueAtTime(0.001, now + 0.15);
        osc.start(now);
        osc.stop(now + 0.16);
      } else if (type === "hit") {
        osc.type = "sine";
        osc.frequency.setValueAtTime(260, now);
        osc.frequency.exponentialRampToValueAtTime(520, now + 0.12);
        gain.gain.setValueAtTime(0.11, now);
        gain.gain.exponentialRampToValueAtTime(0.001, now + 0.13);
        osc.start(now);
        osc.stop(now + 0.14);
      } else if (type === "defeat") {
        osc.type = "triangle";
        osc.frequency.setValueAtTime(440, now);
        osc.frequency.setValueAtTime(554, now + 0.08);
        osc.frequency.setValueAtTime(659, now + 0.16);
        osc.frequency.setValueAtTime(880, now + 0.24);
        gain.gain.setValueAtTime(0.12, now);
        gain.gain.exponentialRampToValueAtTime(0.001, now + 0.42);
        osc.start(now);
        osc.stop(now + 0.44);
      }
    } catch {
      // Ignore audio errors if blocked by browser autoplay policy
    }
  };

  const fireWandBeam = () => {
    const now = performance.now();
    if (now - state.lastBeamAt < 260) return;
    state.lastBeamAt = now;

    const look = AVATAR_LOOKS.find((item) => item.id === state.avatarId) || AVATAR_LOOKS[0];
    const accent = look.accent || "#ff4fa3";
    const trim = look.trim || "#f3b844";

    let dirX = state.facing.x || 1;
    let dirY = state.facing.y || 0;
    const len = Math.hypot(dirX, dirY) || 1;
    dirX /= len;
    dirY /= len;

    // Gentle auto-aim assist toward active guardian in front of player
    const candidates = activeGuardiansInZone(state.position.zone);
    let bestTarget = null;
    let bestDist = 660;
    for (const g of candidates) {
      const dx = g.x - state.position.x;
      const dy = (g.y - 32) - (state.position.y - 16);
      const dist = Math.hypot(dx, dy);
      if (dist < bestDist && (dx * dirX + dy * dirY) / (dist || 1) > 0.35) {
        bestDist = dist;
        bestTarget = { dx: dx / dist, dy: dy / dist };
      }
    }
    if (bestTarget) {
      dirX = dirX * 0.55 + bestTarget.dx * 0.45;
      dirY = dirY * 0.55 + bestTarget.dy * 0.45;
      const nLen = Math.hypot(dirX, dirY) || 1;
      dirX /= nLen;
      dirY /= nLen;
    }

    const startX = state.position.x + (dirX >= 0 ? 32 : -32);
    const startY = state.position.y - 18;
    const speed = 790;
    state.beams.push({
      zone: state.position.zone,
      x: startX,
      y: startY,
      vx: dirX * speed,
      vy: dirY * speed,
      life: 0.95,
      color: accent,
      trim,
    });
    spawnBurst(startX, startY, trim, 6);
    playCombatTone("beam");
  };

  const drawSingleGuardian = (ctx, guardian, time, now) => {
    const gState = getGuardianState(guardian);
    const hover = Math.sin(time * 2.6 + guardian.x * 0.01) * 6;
    const charging = gState.chargeUntil > now;
    const flashing = gState.hitFlashUntil > now;
    const x = guardian.x;
    const y = guardian.y + hover;

    ctx.save();
    ctx.translate(x, y);

    // Threat aura on ground
    const auraPulse = 1 + Math.sin(time * 4) * 0.08;
    ctx.fillStyle = charging ? "rgb(255 82 128 / 34%)" : "rgb(35 28 54 / 28%)";
    ctx.beginPath();
    ctx.ellipse(0, 26 - hover * 0.5, 46 * auraPulse, 15 * auraPulse, 0, 0, Math.PI * 2);
    ctx.fill();

    // Floating Shoulder Crystal Wings
    for (const side of [-1, 1]) {
      const wingBob = Math.sin(time * 3.4 + side) * 5;
      ctx.fillStyle = flashing ? "#ffffff" : guardian.secondary;
      ctx.strokeStyle = "#1c182e";
      ctx.lineWidth = 2.4;
      ctx.beginPath();
      ctx.moveTo(side * 30, -56 + wingBob);
      ctx.lineTo(side * 54, -76 + wingBob);
      ctx.lineTo(side * 46, -34 + wingBob);
      ctx.closePath();
      ctx.fill();
      ctx.stroke();
    }

    // Heavy Armored Torso
    ctx.fillStyle = flashing ? "#ffffff" : guardian.primary;
    ctx.strokeStyle = "#1c182e";
    ctx.lineWidth = 2.8;
    ctx.beginPath();
    ctx.moveTo(-28, -68);
    ctx.lineTo(28, -68);
    ctx.lineTo(36, -24);
    ctx.lineTo(18, 6);
    ctx.lineTo(-18, 6);
    ctx.lineTo(-36, -24);
    ctx.closePath();
    ctx.fill();
    ctx.stroke();

    // Armor trim plates
    ctx.strokeStyle = guardian.secondary;
    ctx.lineWidth = 2.5;
    ctx.beginPath();
    ctx.moveTo(-22, -52);
    ctx.lineTo(0, -36);
    ctx.lineTo(22, -52);
    ctx.stroke();

    // Glowing Power Core in chest
    ctx.save();
    ctx.shadowColor = charging ? "#ff477e" : guardian.secondary;
    ctx.shadowBlur = charging ? 20 : 12;
    ctx.fillStyle = charging ? "#ff477e" : guardian.secondary;
    ctx.beginPath();
    ctx.moveTo(0, -44);
    ctx.lineTo(12, -30);
    ctx.lineTo(0, -16);
    ctx.lineTo(-12, -30);
    ctx.closePath();
    ctx.fill();
    ctx.restore();

    // Horned Boss Crown / Helmet
    ctx.fillStyle = flashing ? "#ffffff" : guardian.primary;
    ctx.beginPath();
    ctx.arc(0, -76, 22, 0, Math.PI * 2);
    ctx.fill();
    ctx.stroke();

    // Horns
    ctx.fillStyle = guardian.secondary;
    ctx.beginPath();
    ctx.moveTo(-16, -88);
    ctx.lineTo(-30, -112);
    ctx.lineTo(-8, -94);
    ctx.closePath();
    ctx.moveTo(16, -88);
    ctx.lineTo(30, -112);
    ctx.lineTo(8, -94);
    ctx.closePath();
    ctx.fill();
    ctx.stroke();

    // Glowing Fierce Eyes
    ctx.save();
    ctx.shadowColor = guardian.eye;
    ctx.shadowBlur = 10;
    ctx.fillStyle = guardian.eye;
    ctx.beginPath();
    ctx.moveTo(-13, -78);
    ctx.lineTo(-4, -74);
    ctx.lineTo(-12, -71);
    ctx.closePath();
    ctx.moveTo(13, -78);
    ctx.lineTo(4, -74);
    ctx.lineTo(12, -71);
    ctx.closePath();
    ctx.fill();
    ctx.restore();

    // Floating Name & 4-Segment HP Bar above Guardian
    ctx.fillStyle = "#fff8ee";
    ctx.font = "800 11px sans-serif";
    ctx.textAlign = "center";
    ctx.fillText(guardian.name.toUpperCase(), 0, -134);

    const barW = 76;
    const barH = 10;
    const startX = -barW / 2;
    const barY = -126;
    drawRoundRect(ctx, startX - 3, barY - 3, barW + 6, barH + 6, 5, "rgb(20 18 36 / 82%)", "#ffffff");
    const segW = (barW - 6) / guardian.maxHp;
    for (let i = 0; i < guardian.maxHp; i++) {
      const filled = i < gState.hp;
      drawRoundRect(
        ctx,
        startX + i * (segW + 2),
        barY,
        segW,
        barH,
        2.5,
        filled ? "#ff4fa3" : "rgb(255 255 255 / 16%)"
      );
    }

    ctx.restore();
  };

  const drawGuardiansAndBeams = (ctx, time, now) => {
    const zone = state.position.zone;
    for (const guardian of activeGuardiansInZone(zone)) {
      drawSingleGuardian(ctx, guardian, time, now);
    }

    // Draw Wand Starlight Beams
    for (const beam of state.beams) {
      if (beam.zone !== zone) continue;
      const angle = Math.atan2(beam.vy, beam.vx);
      ctx.save();
      ctx.translate(beam.x, beam.y);
      ctx.rotate(angle);
      ctx.shadowColor = beam.color;
      ctx.shadowBlur = 16;

      // Outer neon laser lance
      const grad = ctx.createLinearGradient(-46, 0, 18, 0);
      grad.addColorStop(0, "rgb(255 79 163 / 0%)");
      grad.addColorStop(0.55, beam.color);
      grad.addColorStop(1, "#ffffff");
      ctx.strokeStyle = grad;
      ctx.lineWidth = 8;
      ctx.lineCap = "round";
      ctx.beginPath();
      ctx.moveTo(-46, 0);
      ctx.lineTo(16, 0);
      ctx.stroke();

      // Inner white core
      ctx.strokeStyle = "#ffffff";
      ctx.lineWidth = 3;
      ctx.beginPath();
      ctx.moveTo(-26, 0);
      ctx.lineTo(16, 0);
      ctx.stroke();

      // Spinning star head
      ctx.rotate(time * 14);
      ctx.fillStyle = beam.trim;
      ctx.beginPath();
      for (let p = 0; p < 8; p++) {
        const r = p % 2 === 0 ? 9 : 3.5;
        const a = (p * Math.PI) / 4;
        if (p === 0) ctx.moveTo(Math.cos(a) * r, Math.sin(a) * r);
        else ctx.lineTo(Math.cos(a) * r, Math.sin(a) * r);
      }
      ctx.closePath();
      ctx.fill();
      ctx.restore();
    }

    // Draw Guardian Shockwave Orbs
    for (const wave of state.enemyWaves) {
      if (wave.zone !== zone) continue;
      ctx.save();
      ctx.translate(wave.x, wave.y);
      ctx.shadowColor = "#ff477e";
      ctx.shadowBlur = 14;
      ctx.fillStyle = "#ff477e";
      ctx.beginPath();
      ctx.arc(0, 0, 12, 0, Math.PI * 2);
      ctx.fill();
      ctx.strokeStyle = "#fff2b2";
      ctx.lineWidth = 2.5;
      ctx.beginPath();
      ctx.arc(0, 0, 16 + Math.sin(time * 16) * 2, 0, Math.PI * 2);
      ctx.stroke();
      ctx.restore();
    }
  };

  const updateCombat = (delta, now) => {
    const zone = state.position.zone;
    const guardians = activeGuardiansInZone(zone);

    // 1. Update Wand Beams & check hits on Guardians
    state.beams = state.beams.filter((beam) => beam.life > 0);
    for (const beam of state.beams) {
      beam.x += beam.vx * delta;
      beam.y += beam.vy * delta;
      beam.life -= delta;
      if (beam.x < 0 || beam.x > WORLD.width || beam.y < 0 || beam.y > WORLD.height) {
        beam.life = 0;
        continue;
      }
      if (beam.zone !== zone) continue;
      for (const guardian of guardians) {
        const hitDist = Math.hypot(beam.x - guardian.x, beam.y - (guardian.y - 36));
        if (hitDist < 58) {
          beam.life = 0;
          const gState = getGuardianState(guardian);
          gState.hp = Math.max(0, gState.hp - 1);
          gState.hitFlashUntil = now + 150;
          spawnBurst(beam.x, beam.y, "#ff85be", 16);
          spawnBurst(beam.x, beam.y, "#ffe078", 10);

          if (gState.hp <= 0) {
            state.defeatedGuardians.add(guardian.id);
            playCombatTone("defeat");
            spawnBurst(guardian.x, guardian.y - 36, "#ff4fa3", 32);
            spawnBurst(guardian.x, guardian.y - 36, "#ffd866", 28);
            addFloatingText(guardian.x, guardian.y - 75, "GUARDIAN DEFEATED! ★", "#fff5b8");
            toast(isArabic() ? `تمت هزيمة الحارس وفتح البوابة!` : `${guardian.name} defeated! The portal is now open!`, 3400);
            updateInteraction();
          } else {
            playCombatTone("hit");
            addFloatingText(
              guardian.x,
              guardian.y - 82,
              `-1 ★ (${gState.hp}/${guardian.maxHp})`,
              "#ffd1e6"
            );
          }
          break;
        }
      }
    }

    // 2. Guardian Periodic Energy Wave Attack (dodgeable by Jump or Dash)
    for (const guardian of guardians) {
      const gState = getGuardianState(guardian);
      const dx = state.position.x - guardian.x;
      const dy = state.position.y - guardian.y;
      const dist = Math.hypot(dx, dy);
      if (dist < 480 && now - gState.lastAttackAt > 3100) {
        gState.lastAttackAt = now;
        gState.chargeUntil = now + 380;
        const n = dist || 1;
        state.enemyWaves.push({
          zone,
          x: guardian.x + (dx / n) * 34,
          y: guardian.y - 10,
          vx: (dx / n) * 265,
          vy: (dy / n) * 265,
          life: 2.2,
        });
      }
    }

    state.enemyWaves = state.enemyWaves.filter((wave) => wave.life > 0);
    const jumpProgress = state.jumpUntil > now ? 1 - (state.jumpUntil - now) / 560 : 0;
    const jumpHeight = state.jumpUntil > now ? Math.sin(jumpProgress * Math.PI) * 52 : 0;
    const dashActive = state.dashUntil > now;

    for (const wave of state.enemyWaves) {
      wave.x += wave.vx * delta;
      wave.y += wave.vy * delta;
      wave.life -= delta;
      if (wave.zone !== zone) continue;
      const distToPlayer = Math.hypot(wave.x - state.position.x, wave.y - state.position.y);
      if (distToPlayer < 26) {
        if (jumpHeight > 22 || dashActive) {
          wave.life = 0;
          addFloatingText(state.position.x, state.position.y - 54, "DODGED!", "#9ef6ff");
        } else if (now > state.playerHitUntil) {
          wave.life = 0;
          state.playerHitUntil = now + 900;
          const pushDir = Math.sign(wave.vx) || -1;
          state.position.x = Math.min(WORLD.width - 40, Math.max(40, state.position.x + pushDir * 36));
          spawnBurst(state.position.x, state.position.y - 16, "#ff6b9d", 10);
          addFloatingText(state.position.x, state.position.y - 52, "JUMP OR DASH!", "#ffc2d9");
        }
      }
    }
  };

  const drawNeenAvatar = (ctx, look, runCycle, time = 0, isMoving = false, isDash = false) => {
    const outline = "#2a1f3d";
    const accent = look.accent || "#ff4fa3";
    const trim = look.trim || "#f3b844";
    const jacket = look.jacket || "#ff6fb5";
    const sleeve = look.sleeve || "#ff94c8";
    const stride = runCycle * 3.8;
    const hairWave = Math.sin(time * (isMoving ? 13 : 3.2)) * (isMoving ? 7 : 3);
    const windPull = isDash ? 14 : isMoving ? 7 : 1.5;

    ctx.lineCap = "round";
    ctx.lineJoin = "round";

    // 1. Long Flowing Back Hair (sways with movement & wind)
    ctx.save();
    const backHairGrad = ctx.createLinearGradient(0, -92, -10, -32);
    backHairGrad.addColorStop(0, "#fff5fa");
    backHairGrad.addColorStop(0.6, "#eadcf2");
    backHairGrad.addColorStop(1, "#f7c6e0");
    ctx.fillStyle = backHairGrad;
    ctx.strokeStyle = outline;
    ctx.lineWidth = 2.1;
    ctx.beginPath();
    ctx.moveTo(-14, -84);
    ctx.quadraticCurveTo(-24 - windPull * 0.6, -62, -22 - windPull + hairWave * 0.5, -36);
    ctx.quadraticCurveTo(-8, -32 + hairWave * 0.3, 0, -38);
    ctx.quadraticCurveTo(8, -32 - hairWave * 0.3, 21 - windPull * 0.4 + hairWave * 0.5, -36);
    ctx.quadraticCurveTo(23, -62, 14, -84);
    ctx.closePath();
    ctx.fill();
    ctx.stroke();
    ctx.restore();

    // 2. Sheer Neon Ribbon Wings / Cape behind shoulders
    ctx.save();
    const wingGrad = ctx.createLinearGradient(-36, -64, 12, -22);
    wingGrad.addColorStop(0, accent);
    wingGrad.addColorStop(0.55, "rgb(255 158 208 / 82%)");
    wingGrad.addColorStop(1, trim);
    ctx.fillStyle = wingGrad;
    ctx.strokeStyle = outline;
    ctx.lineWidth = 1.9;
    ctx.beginPath();
    ctx.moveTo(-7, -61);
    ctx.quadraticCurveTo(-28 - windPull, -56 + hairWave * 0.4, -35 - windPull, -31 + hairWave);
    ctx.quadraticCurveTo(-21 - windPull * 0.5, -26 - hairWave * 0.3, -10, -34);
    ctx.closePath();
    ctx.fill();
    ctx.stroke();
    ctx.restore();

    // 3. Legs, Thigh-High Stockings & Cute Platform Gamer Boots
    ctx.strokeStyle = outline;
    ctx.lineWidth = 9.5;
    ctx.beginPath();
    ctx.moveTo(-5.5, -26);
    ctx.lineTo(-7 - stride, -10);
    ctx.moveTo(5.5, -26);
    ctx.lineTo(7 + stride, -10);
    ctx.stroke();

    // Skin upper thigh
    ctx.strokeStyle = "#f7d6c4";
    ctx.lineWidth = 6;
    ctx.stroke();

    // White/pastel thigh-high stockings with neon top trim
    ctx.strokeStyle = "#ffffff";
    ctx.lineWidth = 6.2;
    ctx.beginPath();
    ctx.moveTo(-6 - stride * 0.35, -21);
    ctx.lineTo(-7 - stride, -10);
    ctx.moveTo(6 + stride * 0.35, -21);
    ctx.lineTo(7 + stride, -10);
    ctx.stroke();

    ctx.strokeStyle = accent;
    ctx.lineWidth = 2.2;
    ctx.beginPath();
    ctx.moveTo(-9 - stride * 0.35, -21);
    ctx.lineTo(-3 - stride * 0.35, -21);
    ctx.moveTo(3 + stride * 0.35, -21);
    ctx.lineTo(9 + stride * 0.35, -21);
    ctx.stroke();

    // Cute platform girl boots with neon soles & mini bows
    drawRoundRect(ctx, -13.5 - stride, -11, 14.5, 10, 4.5, "#ffffff", outline);
    drawRoundRect(ctx, -1 + stride, -11, 14.5, 10, 4.5, "#ffffff", outline);
    drawRoundRect(ctx, -13.5 - stride, -3.5, 14.5, 3.8, 2, accent);
    drawRoundRect(ctx, -1 + stride, -3.5, 14.5, 3.8, 2, accent);
    // Boot ribbon accents
    ctx.fillStyle = trim;
    ctx.beginPath();
    ctx.arc(-6 - stride, -9.5, 2.2, 0, Math.PI * 2);
    ctx.arc(6 + stride, -9.5, 2.2, 0, Math.PI * 2);
    ctx.fill();

    // 4. Flared Pleated Skater Skirt & Waist Ribbon Bow
    const skirtGrad = ctx.createLinearGradient(0, -40, 0, -24);
    skirtGrad.addColorStop(0, jacket);
    skirtGrad.addColorStop(1, accent);
    ctx.fillStyle = skirtGrad;
    ctx.strokeStyle = outline;
    ctx.lineWidth = 2.2;
    ctx.beginPath();
    ctx.moveTo(-10, -39);
    ctx.lineTo(10, -39);
    ctx.lineTo(17.5, -25);
    ctx.quadraticCurveTo(9, -22.5, 0, -24.5);
    ctx.quadraticCurveTo(-9, -22.5, -17.5, -25);
    ctx.closePath();
    ctx.fill();
    ctx.stroke();

    // Skirt pleats & white hem lace
    ctx.strokeStyle = "#ffffff";
    ctx.lineWidth = 1.6;
    ctx.beginPath();
    ctx.moveTo(-16, -26.5);
    ctx.quadraticCurveTo(0, -23.5, 16, -26.5);
    ctx.moveTo(-5, -38);
    ctx.lineTo(-7.5, -25.5);
    ctx.moveTo(0, -38);
    ctx.lineTo(0, -25);
    ctx.moveTo(5, -38);
    ctx.lineTo(7.5, -25.5);
    ctx.stroke();

    // Waist belt & cute side bow
    drawRoundRect(ctx, -10.5, -40.5, 21, 4.5, 2, "#ffffff", outline);
    ctx.fillStyle = trim;
    ctx.beginPath();
    ctx.moveTo(0, -38.2);
    ctx.lineTo(-5, -41);
    ctx.lineTo(-5, -35.5);
    ctx.closePath();
    ctx.moveTo(0, -38.2);
    ctx.lineTo(5, -41);
    ctx.lineTo(5, -35.5);
    ctx.closePath();
    ctx.fill();
    ctx.beginPath();
    ctx.arc(0, -38.2, 2.1, 0, Math.PI * 2);
    ctx.fill();

    // 5. Back Arm
    ctx.strokeStyle = outline;
    ctx.lineWidth = 9.5;
    ctx.beginPath();
    ctx.moveTo(-10.5, -59);
    ctx.quadraticCurveTo(-18 - stride * 0.8, -52, -17 - stride * 0.8, -42);
    ctx.stroke();
    ctx.strokeStyle = sleeve;
    ctx.lineWidth = 6;
    ctx.stroke();
    ctx.fillStyle = "#f7d6c4";
    ctx.beginPath();
    ctx.arc(-17 - stride * 0.8, -40.5, 3.6, 0, Math.PI * 2);
    ctx.fill();

    // 6. Fitted Cropped Gamer Top / Bolero Jacket
    ctx.fillStyle = "#f7d6c4";
    ctx.fillRect(-3.5, -66, 7, 8);

    ctx.fillStyle = jacket;
    ctx.strokeStyle = outline;
    ctx.lineWidth = 2.2;
    ctx.beginPath();
    ctx.moveTo(-11.5, -60);
    ctx.quadraticCurveTo(-8, -65, -4, -64);
    ctx.lineTo(4, -64);
    ctx.quadraticCurveTo(8, -65, 11.5, -60);
    ctx.lineTo(9.5, -40);
    ctx.lineTo(-9.5, -40);
    ctx.closePath();
    ctx.fill();
    ctx.stroke();

    // Inner white crop-top V-panel & glowing heart emblem
    ctx.fillStyle = "#ffffff";
    ctx.beginPath();
    ctx.moveTo(-5.5, -62);
    ctx.lineTo(5.5, -62);
    ctx.lineTo(4, -41);
    ctx.lineTo(-4, -41);
    ctx.closePath();
    ctx.fill();

    // Glowing Heart Emblem on chest
    ctx.save();
    ctx.shadowColor = accent;
    ctx.shadowBlur = 8;
    ctx.fillStyle = accent;
    ctx.beginPath();
    ctx.moveTo(0, -49.5);
    ctx.bezierCurveTo(-4.5, -53.5, -6.5, -48.5, 0, -44.5);
    ctx.bezierCurveTo(6.5, -48.5, 4.5, -53.5, 0, -49.5);
    ctx.fill();
    ctx.restore();

    // 7. Front Arm + Glowing Starlight Neon Wand / Blade
    const handX = 16.5 + stride * 0.8;
    const handY = -41.5;
    ctx.save();
    ctx.translate(handX, handY);
    ctx.rotate(-0.42 + stride * 0.04);
    ctx.shadowColor = accent;
    ctx.shadowBlur = isDash ? 16 : 10;
    ctx.strokeStyle = accent;
    ctx.lineWidth = 4.2;
    ctx.beginPath();
    ctx.moveTo(2, -1);
    ctx.lineTo(34, -14);
    ctx.stroke();
    ctx.strokeStyle = "#ffffff";
    ctx.lineWidth = 1.6;
    ctx.beginPath();
    ctx.moveTo(4, -2);
    ctx.lineTo(32, -13);
    ctx.stroke();
    // Large radiant Starlight Wand head & halo ring
    ctx.strokeStyle = "#ffffff";
    ctx.lineWidth = 1.6;
    ctx.beginPath();
    ctx.arc(38, -14, 9.5, 0, Math.PI * 2);
    ctx.stroke();

    ctx.fillStyle = trim;
    ctx.beginPath();
    for (let sp = 0; sp < 10; sp++) {
      const r = sp % 2 === 0 ? 10 : 4.2;
      const a = (sp * Math.PI) / 5 - Math.PI / 2;
      const sx = 38 + Math.cos(a) * r;
      const sy = -14 + Math.sin(a) * r;
      if (sp === 0) ctx.moveTo(sx, sy);
      else ctx.lineTo(sx, sy);
    }
    ctx.closePath();
    ctx.fill();
    ctx.shadowBlur = 0;
    drawRoundRect(ctx, -4, -3, 9, 4, 2, trim, outline);
    ctx.restore();

    ctx.strokeStyle = outline;
    ctx.lineWidth = 9.5;
    ctx.beginPath();
    ctx.moveTo(10.5, -59);
    ctx.quadraticCurveTo(18 + stride * 0.8, -52, handX, handY);
    ctx.stroke();
    ctx.strokeStyle = sleeve;
    ctx.lineWidth = 6;
    ctx.stroke();
    ctx.fillStyle = "#f7d6c4";
    ctx.beginPath();
    ctx.arc(handX, handY, 3.6, 0, Math.PI * 2);
    ctx.fill();

    // 8. Cute Twin-Tails (Pigtails) & Ribbon Bows on sides of head
    for (const side of [-1, 1]) {
      const sway = hairWave * (side === -1 ? 0.8 : -0.5) - windPull * 0.45;
      ctx.fillStyle = "#f5edf8";
      ctx.strokeStyle = outline;
      ctx.lineWidth = 2;
      ctx.beginPath();
      ctx.moveTo(side * 14, -85);
      ctx.quadraticCurveTo(side * 26 + sway * 0.4, -76, side * 23 + sway, -53);
      ctx.quadraticCurveTo(side * 16 + sway * 0.5, -56, side * 14, -74);
      ctx.closePath();
      ctx.fill();
      ctx.stroke();

      // Side ribbon bow
      ctx.fillStyle = accent;
      ctx.beginPath();
      ctx.moveTo(side * 15, -85);
      ctx.lineTo(side * 20, -89);
      ctx.lineTo(side * 19, -82);
      ctx.closePath();
      ctx.moveTo(side * 15, -85);
      ctx.lineTo(side * 12, -89);
      ctx.lineTo(side * 11, -83);
      ctx.closePath();
      ctx.fill();
      ctx.fillStyle = trim;
      ctx.beginPath();
      ctx.arc(side * 15, -85, 2.2, 0, Math.PI * 2);
      ctx.fill();
    }

    // 9. Cat-Ear Gamer Headset anchored to circular head
    ctx.fillStyle = "#ffffff";
    ctx.strokeStyle = outline;
    ctx.lineWidth = 2.1;
    ctx.beginPath();
    ctx.moveTo(-10, -90);
    ctx.lineTo(-19, -108);
    ctx.quadraticCurveTo(-7, -105, -2, -94);
    ctx.closePath();
    ctx.moveTo(10, -90);
    ctx.lineTo(19, -108);
    ctx.quadraticCurveTo(7, -105, 2, -94);
    ctx.closePath();
    ctx.fill();
    ctx.stroke();

    ctx.fillStyle = accent;
    ctx.beginPath();
    ctx.moveTo(-11, -93);
    ctx.lineTo(-16, -103);
    ctx.lineTo(-5, -96);
    ctx.closePath();
    ctx.moveTo(11, -93);
    ctx.lineTo(16, -103);
    ctx.lineTo(5, -96);
    ctx.closePath();
    ctx.fill();

    // 10. True Circular Girl Face, Bangs & Big Expressive Anime Eyes
    ctx.fillStyle = "#f7d6c4";
    ctx.strokeStyle = outline;
    ctx.lineWidth = 2.1;
    ctx.beginPath();
    ctx.arc(0, -79, 16, 0, Math.PI * 2);
    ctx.fill();
    ctx.stroke();

    // Soft rounded bangs hugging the top of the circle
    ctx.fillStyle = "#fff4fa";
    ctx.strokeStyle = outline;
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.arc(0, -80, 16.4, Math.PI * 1.05, Math.PI * 1.95);
    ctx.quadraticCurveTo(10, -85, 5, -88);
    ctx.quadraticCurveTo(0, -83, -5, -88);
    ctx.quadraticCurveTo(-10, -85, -16.4, -80);
    ctx.closePath();
    ctx.fill();
    ctx.stroke();

    // Big Expressive Anime Girl Eyes with Eyelashes & Starlight Sparkles
    for (const side of [-1, 1]) {
      const ex = side * 6.2;
      const ey = -77.5;
      // Eye white
      ctx.fillStyle = "#ffffff";
      ctx.beginPath();
      ctx.ellipse(ex, ey, 3.6, 4.1, 0, 0, Math.PI * 2);
      ctx.fill();
      // Iris
      ctx.fillStyle = accent;
      ctx.beginPath();
      ctx.ellipse(ex, ey + 0.4, 2.7, 3.3, 0, 0, Math.PI * 2);
      ctx.fill();
      // Pupil
      ctx.fillStyle = "#1c1430";
      ctx.beginPath();
      ctx.arc(ex, ey + 0.6, 1.5, 0, Math.PI * 2);
      ctx.fill();
      // Eye sparkles
      ctx.fillStyle = "#ffffff";
      ctx.beginPath();
      ctx.arc(ex - 0.9, ey - 1.1, 1.1, 0, Math.PI * 2);
      ctx.arc(ex + 1, ey + 1.2, 0.55, 0, Math.PI * 2);
      ctx.fill();
      // Curved upper lash & wing lash
      ctx.strokeStyle = outline;
      ctx.lineWidth = 1.9;
      ctx.beginPath();
      ctx.arc(ex, ey - 0.3, 3.8, Math.PI * 1.12, Math.PI * 1.88);
      ctx.stroke();
      ctx.beginPath();
      ctx.moveTo(ex + side * 3.4, ey - 1.6);
      ctx.lineTo(ex + side * 5.2, ey - 2.9);
      ctx.stroke();
    }

    // Rosy cheek blush
    ctx.fillStyle = "rgb(255 105 165 / 38%)";
    ctx.beginPath();
    ctx.ellipse(-9.8, -72.5, 3.1, 1.8, 0, 0, Math.PI * 2);
    ctx.ellipse(9.8, -72.5, 3.1, 1.8, 0, 0, Math.PI * 2);
    ctx.fill();

    // Cute sweet smile
    ctx.strokeStyle = "#b85d6f";
    ctx.lineWidth = 1.6;
    ctx.beginPath();
    ctx.moveTo(-3.2, -69.5);
    ctx.quadraticCurveTo(0, -66.8, 3.2, -69.5);
    ctx.stroke();

    // 11. Princess Tiara & Glowing Gem
    ctx.save();
    ctx.shadowColor = trim;
    ctx.shadowBlur = 7;
    ctx.strokeStyle = trim;
    ctx.lineWidth = 2.8;
    ctx.beginPath();
    ctx.moveTo(-8, -95.5);
    ctx.lineTo(-4, -99.5);
    ctx.lineTo(0, -95.5);
    ctx.lineTo(4, -99.5);
    ctx.lineTo(8, -95.5);
    ctx.stroke();
    ctx.fillStyle = accent;
    ctx.strokeStyle = "#ffffff";
    ctx.lineWidth = 1.2;
    ctx.beginPath();
    ctx.moveTo(0, -102.5);
    ctx.lineTo(3.8, -98);
    ctx.lineTo(0, -93.5);
    ctx.lineTo(-3.8, -98);
    ctx.closePath();
    ctx.fill();
    ctx.stroke();
    ctx.restore();
  };

  const drawAvatarPortrait = (look) => {
    if (!(avatarPortrait instanceof HTMLCanvasElement)) return;
    const portrait = avatarPortrait.getContext("2d");
    if (!portrait) return;
    portrait.clearRect(0, 0, avatarPortrait.width, avatarPortrait.height);
    portrait.save();
    portrait.translate(56, 111);
    portrait.scale(0.95, 0.95);
    drawNeenAvatar(portrait, look, 0, 0, false, false);
    portrait.restore();
  };

  const drawCharacter = (ctx, time, now) => {
    const moving = state.keys.size > 0 || Math.hypot(state.joystickVector.x, state.joystickVector.y) > 0.08;
    const jumpProgress = state.jumpUntil > now ? 1 - (state.jumpUntil - now) / 560 : 0;
    const jumpHeight = state.jumpUntil > now ? Math.sin(jumpProgress * Math.PI) * 52 : 0;
    const dashActive = state.dashUntil > now;
    const runCycle = moving ? Math.sin(time * 14) : Math.sin(time * 2.2) * 0.14;
    const bob = moving ? Math.sin(time * 14) * 2.8 : Math.sin(time * 2.2) * 2.2;
    const squashX = state.jumpUntil > now && jumpProgress < 0.12 ? 1.14 : 1;
    const squashY = state.jumpUntil > now && jumpProgress < 0.12 ? 0.82 : 1;
    const look = AVATAR_LOOKS.find((item) => item.id === state.avatarId) || AVATAR_LOOKS[0];
    const accent = look.accent || "#ff4fa3";
    const trim = look.trim || "#f3b844";
    const x = state.position.x;
    const y = state.position.y - jumpHeight + bob;
    const facingSign = state.facing.x < -0.15 ? -1 : 1;

    ctx.save();
    ctx.translate(x, y);

    // Pulsing Neon Power Aura Ring on the ground under character
    const auraY = 27 + jumpHeight * 0.42;
    const pulse = 1 + Math.sin(time * 4.5) * 0.08;
    ctx.save();
    ctx.translate(0, auraY);
    const auraGrad = ctx.createRadialGradient(0, 0, 4, 0, 0, 44 * pulse);
    auraGrad.addColorStop(0, "rgb(255 255 255 / 38%)");
    auraGrad.addColorStop(0.55, "rgb(255 79 163 / 24%)");
    auraGrad.addColorStop(1, "rgb(255 79 163 / 0%)");
    ctx.fillStyle = auraGrad;
    ctx.beginPath();
    ctx.ellipse(0, 0, 44 * pulse, 16 * pulse, 0, 0, Math.PI * 2);
    ctx.fill();

    ctx.strokeStyle = accent;
    ctx.lineWidth = 2.2;
    ctx.globalAlpha = 0.65;
    ctx.beginPath();
    ctx.ellipse(0, 0, 34 * pulse, 12 * pulse, 0, 0, Math.PI * 2);
    ctx.stroke();

    ctx.strokeStyle = trim;
    ctx.lineWidth = 1.5;
    ctx.setLineDash([8, 6]);
    ctx.lineDashOffset = -time * 24;
    ctx.beginPath();
    ctx.ellipse(0, 0, 40 * pulse, 14.5 * pulse, 0, 0, Math.PI * 2);
    ctx.stroke();
    ctx.restore();

    // Ground shadow
    ctx.fillStyle = "rgb(28 36 58 / 26%)";
    ctx.beginPath();
    ctx.ellipse(0, auraY, 30 - jumpHeight * 0.14, 11 - jumpHeight * 0.04, 0, 0, Math.PI * 2);
    ctx.fill();

    // Heroic scale & dynamic forward lean
    const heroScale = 1.26;
    ctx.scale(squashX * heroScale, squashY * heroScale);
    ctx.scale(facingSign, 1);
    if (moving || dashActive) {
      ctx.rotate(dashActive ? 0.14 : 0.06);
    }

    if (dashActive) {
      for (let echo = 3; echo > 0; echo--) {
        ctx.save();
        ctx.globalAlpha = 0.14 + (4 - echo) * 0.045;
        ctx.translate(-echo * 17, 25);
        drawNeenAvatar(ctx, look, runCycle, time, moving, true);
        ctx.restore();
      }
    }

    ctx.translate(0, 25);
    drawNeenAvatar(ctx, look, runCycle, time, moving, dashActive);

    // Rising energy sparks around the hero
    for (let s = 0; s < 4; s++) {
      const angle = time * 3.2 + s * 1.57;
      const sx = Math.cos(angle) * 24;
      const sy = -22 - ((time * 36 + s * 24) % 78);
      ctx.fillStyle = s % 2 === 0 ? accent : trim;
      ctx.globalAlpha = 0.75;
      ctx.beginPath();
      ctx.arc(sx, sy, 2.2, 0, Math.PI * 2);
      ctx.fill();
    }

    ctx.restore();
  };

  const drawParticles = (ctx, delta) => {
    state.particles = state.particles.filter((particle) => particle.life > 0);
    for (const particle of state.particles) {
      particle.x += particle.vx * delta;
      particle.y += particle.vy * delta;
      particle.vy += 48 * delta;
      particle.life -= delta;
      ctx.globalAlpha = Math.max(0, particle.life);
      ctx.fillStyle = particle.color;
      ctx.beginPath();
      ctx.arc(particle.x, particle.y, particle.size * Math.max(.25, particle.life), 0, Math.PI * 2);
      ctx.fill();
    }
    ctx.globalAlpha = 1;
    state.floatingTexts = state.floatingTexts.filter((entry) => entry.life > 0);
    for (const entry of state.floatingTexts) {
      entry.y -= 30 * delta;
      entry.life -= delta;
      ctx.globalAlpha = Math.min(1, entry.life * 1.5);
      ctx.fillStyle = "rgb(40 73 83 / 75%)";
      ctx.font = "800 16px sans-serif";
      ctx.textAlign = "center";
      ctx.fillText(entry.text, entry.x + 2, entry.y + 2);
      ctx.fillStyle = entry.color;
      ctx.fillText(entry.text, entry.x, entry.y);
    }
    ctx.globalAlpha = 1;
  };

  const renderFrame = (now) => {
    const delta = Math.min(.04, Math.max(0, (now - (state.lastFrame || now)) / 1000));
    state.lastFrame = now;
    const time = now / 1000;
    const bounds = canvas.getBoundingClientRect();
    const scaleX = canvas.width / WORLD.viewWidth;
    const scaleY = canvas.height / WORLD.viewHeight;
    context.setTransform(scaleX, 0, 0, scaleY, 0, 0);
    const cameraX = Math.max(0, Math.min(WORLD.width - WORLD.viewWidth, state.position.x - WORLD.viewWidth / 2));
    const cameraY = Math.max(0, Math.min(WORLD.height - WORLD.viewHeight, state.position.y - WORLD.viewHeight / 2));
    context.save();
    context.clearRect(0, 0, WORLD.viewWidth, WORLD.viewHeight);
    context.translate(-cameraX, -cameraY);
    drawBackground(context, state.position.zone, time);
    drawWorldObjects(context, time);
    drawGuardiansAndBeams(context, time, now);
    drawCharacter(context, time, now);
    drawParticles(context, delta);
    context.restore();

    if (state.toastUntil && now > state.toastUntil) {
      const toastNode = document.querySelector("[data-toast]");
      if (toastNode) toastNode.hidden = true;
      state.toastUntil = 0;
    }

    updateInteraction();
    updateMovement(delta, now);
    updateCombat(delta, now);
    updateDashStatus(now);
    if (state.dirty && now - state.lastPeriodicSync > 1800 && state.authenticated && !state.busy) {
      state.lastPeriodicSync = now;
      scheduleProgressSync();
    }
    state.animationFrame = requestAnimationFrame(renderFrame);
  };

  const currentInput = () => {
    let x = state.joystickVector.x;
    let y = state.joystickVector.y;
    if (state.keys.has("ArrowLeft") || state.keys.has("a")) x -= 1;
    if (state.keys.has("ArrowRight") || state.keys.has("d")) x += 1;
    if (state.keys.has("ArrowUp") || state.keys.has("w")) y -= 1;
    if (state.keys.has("ArrowDown") || state.keys.has("s")) y += 1;
    const magnitude = Math.hypot(x, y);
    if (magnitude > 1) {
      x /= magnitude;
      y /= magnitude;
    }
    return { x, y };
  };

  const updateMovement = (delta, now) => {
    const input = currentInput();
    const magnitude = Math.hypot(input.x, input.y);
    if (magnitude > .05) {
      state.facing = { x: input.x / magnitude, y: input.y / magnitude };
      const dashing = now < state.dashUntil;
      const speed = dashing ? 585 : 245;
      state.position.x = Math.min(WORLD.width - 35, Math.max(35, state.position.x + input.x * speed * delta));
      state.position.y = Math.min(WORLD.height - 35, Math.max(35, state.position.y + input.y * speed * delta));
      if (Math.random() < delta * 10) {
        spawnBurst(
          state.position.x - state.facing.x * 13,
          state.position.y + 12,
          state.position.zone === "loot_canyon" ? "#f5c084" : "#f7e5a1",
          1,
        );
      }
      state.dirty = true;
    }
  };

  const jump = () => {
    const now = performance.now();
    if (state.jumpUntil > now) return;
    state.jumpUntil = now + 560;
    spawnBurst(state.position.x, state.position.y + 14, "#fff1bd", 7);
  };

  const dash = () => {
    const now = performance.now();
    if (now < state.dashCooldownUntil) {
      const remain = Math.ceil((state.dashCooldownUntil - now) / 1000);
      toast(`Dash recharging · ${remain}s`);
      return;
    }
    state.dashUntil = now + 260;
    state.dashCooldownUntil = now + 2100;
    spawnBurst(state.position.x, state.position.y, "#b5f7f3", 10);
  };

  const updateDashStatus = (now) => {
    if (now - state.lastDashHudUpdate < 180) return;
    state.lastDashHudUpdate = now;
    const node = document.querySelector("[data-dash-status]");
    if (!(node instanceof HTMLElement)) return;
    const remaining = Math.max(0, state.dashCooldownUntil - now);
    node.classList.toggle("is-cooling", remaining > 0);
    node.textContent = remaining > 0 ? `Dash ${Math.ceil(remaining / 1000)}s` : "Dash ready";
  };

  const openCodex = () => {
    renderCodex();
    if (!codexDialog.open) codexDialog.showModal();
  };

  const normalizeKey = (key) => ({
    arrowup: "ArrowUp",
    arrowdown: "ArrowDown",
    arrowleft: "ArrowLeft",
    arrowright: "ArrowRight",
  }[key.toLowerCase()] || key.toLowerCase());

  if (avatarSelect instanceof HTMLSelectElement) {
    avatarSelect.addEventListener("change", () => setAvatar(avatarSelect.value, true));
  }

  canvas.addEventListener("pointerdown", (event) => {
    if (event.pointerType === "touch") event.preventDefault();
    canvas.focus({ preventScroll: true });
    if (event.button === 0) {
      const rect = canvas.getBoundingClientRect();
      if (rect.width > 0 && rect.height > 0) {
        const cameraX = Math.max(0, Math.min(WORLD.width - WORLD.viewWidth, state.position.x - WORLD.viewWidth / 2));
        const cameraY = Math.max(0, Math.min(WORLD.height - WORLD.viewHeight, state.position.y - WORLD.viewHeight / 2));
        const worldX = cameraX + ((event.clientX - rect.left) / rect.width) * WORLD.viewWidth;
        const worldY = cameraY + ((event.clientY - rect.top) / rect.height) * WORLD.viewHeight;
        const dx = worldX - state.position.x;
        const dy = worldY - state.position.y;
        const dist = Math.hypot(dx, dy);
        if (dist > 14) {
          state.facing = { x: dx / dist, y: dy / dist };
        }
      }
      fireWandBeam();
    }
  });
  window.addEventListener("keydown", (event) => {
    if (codexDialog.open && event.key !== "Escape") return;
    if (event.target === avatarSelect) return;
    const key = normalizeKey(event.key);
    if (["ArrowUp", "ArrowDown", "ArrowLeft", "ArrowRight", " "].includes(key)) event.preventDefault();
    if (key === " " && !event.repeat) {
      if (state.activeInteraction) void interact();
      else jump();
      return;
    }
    if (key === "e" && !event.repeat) {
      void interact();
      return;
    }
    if (key === "shift" && !event.repeat) {
      dash();
      return;
    }
    if ((key === "f" || key === "j") && !event.repeat) {
      fireWandBeam();
      return;
    }
    if (key === "c" && !event.repeat) {
      openCodex();
      return;
    }
    if (["w", "a", "s", "d", "ArrowUp", "ArrowDown", "ArrowLeft", "ArrowRight"].includes(key)) {
      state.keys.add(key);
    }
  });
  window.addEventListener("keyup", (event) => {
    state.keys.delete(normalizeKey(event.key));
  });
  window.addEventListener("blur", () => state.keys.clear());

  const bindPress = (button, action) => {
    if (!(button instanceof HTMLButtonElement)) return;
    button.addEventListener("pointerdown", (event) => {
      event.preventDefault();
      action();
    });
    button.addEventListener("click", (event) => {
      event.preventDefault();
      if (event.detail === 0) action();
    });
  };
  bindPress(actionButton, () => void interact());
  bindPress(touchActionButton, () => void interact());
  bindPress(jumpButton, jump);
  bindPress(dashButton, dash);
  const beamHudButton = document.querySelector("[data-beam-button]");
  bindPress(beamHudButton, fireWandBeam);
  startButton.addEventListener("click", () => void startAdventure());
  completeButton.addEventListener("click", () => void completeAdventure());

  const codexOpenButton = document.querySelector("[data-codex-open]");
  const codexCloseButton = document.querySelector("[data-codex-close]");
  codexOpenButton?.addEventListener("click", openCodex);
  codexCloseButton?.addEventListener("click", () => codexDialog.close());
  codexDialog.addEventListener("click", (event) => {
    if (event.target === codexDialog) codexDialog.close();
  });
  mapToggle.addEventListener("click", () => {
    const expanded = minimap.classList.toggle("is-expanded");
    mapToggle.setAttribute("aria-expanded", String(expanded));
    mapToggle.textContent = expanded ? "Close" : "Expand";
  });

  if (joystick instanceof HTMLElement && joystickKnob instanceof HTMLElement) {
    const moveJoystick = (event) => {
      const rect = joystick.getBoundingClientRect();
      const radius = rect.width * .39;
      const dx = event.clientX - (rect.left + rect.width / 2);
      const dy = event.clientY - (rect.top + rect.height / 2);
      const distance = Math.min(radius, Math.hypot(dx, dy));
      const angle = Math.atan2(dy, dx);
      const x = (Math.cos(angle) * distance) / radius;
      const y = (Math.sin(angle) * distance) / radius;
      state.joystickVector = { x, y };
      joystickKnob.style.left = `calc(50% + ${x * radius}px)`;
      joystickKnob.style.top = `calc(50% + ${y * radius}px)`;
    };
    joystick.addEventListener("pointerdown", (event) => {
      event.preventDefault();
      state.joystickPointer = event.pointerId;
      joystick.setPointerCapture(event.pointerId);
      moveJoystick(event);
    });
    joystick.addEventListener("pointermove", (event) => {
      if (state.joystickPointer === event.pointerId) {
        event.preventDefault();
        moveJoystick(event);
      }
    });
    const releaseJoystick = (event) => {
      if (state.joystickPointer !== event.pointerId) return;
      state.joystickPointer = null;
      state.joystickVector = { x: 0, y: 0 };
      joystickKnob.style.left = "50%";
      joystickKnob.style.top = "50%";
    };
    joystick.addEventListener("pointerup", releaseJoystick);
    joystick.addEventListener("pointercancel", releaseJoystick);
    joystick.addEventListener("lostpointercapture", releaseJoystick);
  }

  document.addEventListener("visibilitychange", () => {
    if (document.hidden) {
      cancelAnimationFrame(state.animationFrame);
      state.keys.clear();
      if (state.dirty) scheduleProgressSync(true);
    } else {
      state.lastFrame = 0;
      state.animationFrame = requestAnimationFrame(renderFrame);
    }
  });
  const resizeObserver = new ResizeObserver(() => {
    const rect = canvas.getBoundingClientRect();
    const ratio = Math.min(2, window.devicePixelRatio || 1);
    canvas.width = Math.max(1, Math.round(rect.width * ratio));
    canvas.height = Math.max(1, Math.round(rect.height * ratio));
  });
  resizeObserver.observe(canvas);

  const initialCatalog = [
    { id: "golden_compass", name: "Golden Compass", image: "/assets/hero-loot/gold-coin.webp" },
    { id: "neon_relic", name: "Neon Relic", image: "/assets/hero-loot/weapon-ak47.webp" },
    { id: "drift_token", name: "Drift Token", image: "/assets/hero-loot/weapon-mp40.webp" },
    { id: "sun_crate", name: "Sun Crate", image: "/assets/hero-loot/loot-crate.webp" },
    { id: "palm_pack", name: "Palm Pack", image: "/assets/hero-loot/backpack.webp" },
    { id: "snoop_badge", name: "Snoop Badge", image: "/assets/hero-loot/snoop-sticker-01.webp" },
    { id: "reef_sticker", name: "Reef Sticker", image: "/assets/hero-loot/snoop-sticker-02.webp" },
    { id: "sunset_sticker", name: "Sunset Sticker", image: "/assets/hero-loot/snoop-sticker-03.webp" },
    { id: "canyon_sticker", name: "Canyon Sticker", image: "/assets/hero-loot/snoop-sticker-04.webp" },
  ];
  state.codex = initialCatalog;
  setAvatar(state.avatarId);
  loadImages(initialCatalog);
  const langObserver = new MutationObserver(() => {
    renderProgress();
    renderMap();
  });
  langObserver.observe(document.documentElement, { attributes: true, attributeFilter: ["lang", "dir"] });
  renderProgress();
  renderMap();
  state.animationFrame = requestAnimationFrame(renderFrame);
  void loadState();
})();
