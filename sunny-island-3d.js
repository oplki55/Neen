import * as THREE from "./assets/vendor/three/three.module.js";
import { GLTFLoader } from "./assets/vendor/three/addons/loaders/GLTFLoader.js";

const canvas = document.querySelector("#island-canvas");
const message = document.querySelector("#scene-message");
const zoneLabel = document.querySelector("#zone-name");
const coordinates = document.querySelector("#coordinates");
const joystick = document.querySelector("#joystick");
const joystickKnob = document.querySelector("#joystick-knob");

if (!(canvas instanceof HTMLCanvasElement)) {
  throw new Error("Sunny Island 3D canvas is missing.");
}

const WORLD = { width: 160, depth: 100, scale: 10 };
const GROUND_Y = 0.76;
const PLAYER_COLLISION_RADIUS = 0.72;
const CAMERA_OFFSET = new THREE.Vector3(0, 6.3, 12.5);
const CAMERA_DISTANCE = CAMERA_OFFSET.length();
const DEFAULT_CAMERA_PITCH = Math.atan2(CAMERA_OFFSET.y, CAMERA_OFFSET.z);
const CAMERA_PITCH_LIMITS = { min: 0.14, max: 1.12 };
const CAMERA_DRAG_SENSITIVITY = 0.006;
const CAMERA_PITCH_DRAG_SENSITIVITY = 0.004;
const CAMERA_KEY_TURN_SPEED = 2.2;
const CAMERA_KEY_HOLD_DELAY = 180;
const ZONES = [
  { name: "Rim Nam Docks / Moonlit Bay", color: 0x78c850, x: 35, z: 77 },
  { name: "Peak Ruins / Crownleaf Manor", color: 0x6ab946, x: 58, z: 34 },
  { name: "Factory / Skycrate Foundry", color: 0x78c850, x: 118, z: 72 },
  { name: "Clock Tower Summit / Solstice Bellspire", color: 0x6ab946, x: 123, z: 27 },
];
const ZONE_ORDER = ["neon_pier", "palm_grove", "loot_canyon", "sunset_ridge"];
const ZONE_TITLES = Object.fromEntries(ZONE_ORDER.map((id, index) => [id, ZONES[index].name]));
const ZONE_CENTERS = Object.fromEntries(ZONE_ORDER.map((id, index) => [id, { x: ZONES[index].x, z: ZONES[index].z }]));
const NATURE_LAYOUT = {
  palms: [
    [17, 65], [17, 78], [17, 82], [23, 65], [48, 70],
    [43, 19], [73, 18],
  ],
  bushes: [
    [42, 27], [42, 40], [39, 51], [58, 49],
    [68, 51], [79, 45], [70, 22],
  ],
};
const PICKUPS = [
  { id: "snoop_badge", zone: "neon_pier", x: 31, z: 72 },
  { id: "sunset_sticker", zone: "neon_pier", x: 45, z: 64 },
  { id: "sun_crate", zone: "palm_grove", x: 46, z: 32 },
  { id: "palm_pack", zone: "palm_grove", x: 60, z: 24 },
  { id: "reef_sticker", zone: "palm_grove", x: 69, z: 40 },
  { id: "golden_compass", zone: "loot_canyon", x: 114, z: 69 },
  { id: "neon_relic", zone: "loot_canyon", x: 128, z: 79 },
  { id: "drift_token", zone: "sunset_ridge", x: 115, z: 21 },
  { id: "canyon_sticker", zone: "sunset_ridge", x: 135, z: 33 },
];
const BEACONS = [
  { id: "pier-beacon-a", zone: "neon_pier", x: 25, z: 73 },
  { id: "pier-beacon-b", zone: "neon_pier", x: 46, z: 83 },
];
const GUARDIAN_DEFS = [
  { id: "guardian_pier", zone: "neon_pier", x: 58, z: 82, name: "Tideclaw Guardian", hp: 8, color: 0x1e6f7d, accent: 0x5ff5ff },
  { id: "guardian_grove", zone: "palm_grove", x: 72, z: 31, name: "Stonebark Sentinel", hp: 8, color: 0x556b4f, accent: 0x9dff8c },
  { id: "guardian_canyon", zone: "loot_canyon", x: 143, z: 80, name: "Prism Crystal Titan", hp: 10, color: 0x4a386b, accent: 0xd28cff },
  { id: "guardian_ridge", zone: "sunset_ridge", x: 144, z: 23, name: "Solstice Ember Boss", hp: 24, color: 0x6e2c3b, accent: 0xffbe55, boss: true },
];
const DRONE_DEFS = [
  { id: "drone_small", zone: "loot_canyon", x: 103, z: 63, name: "Foundry Drone", hp: 5, color: 0x57b8d5, accent: 0xc3f9ff, kind: "small" },
  { id: "drone_flying", zone: "sunset_ridge", x: 128, z: 38, name: "Sky Scout", hp: 4, color: 0x57b8d5, accent: 0xc3f9ff, kind: "flying" },
];
const WEAPON_STATS = {
  wand: { cooldown: 0.26, damage: 1, speed: 42, range: 48, color: 0xff78bd },
  mp40: { cooldown: 0.105, damage: 1, speed: 50, range: 42, color: 0x70e4ff },
  ak47: { cooldown: 0.62, damage: 3, speed: 56, range: 58, color: 0xffb45c },
};
const SKIN_PALETTES = {
  skin_neon_crown: { accent: 0x65f4ff, trim: 0xff7bd5, jacket: 0x243b66 },
  skin_sakura_bloom: { accent: 0xffafd7, trim: 0xfff0f7, jacket: 0x673e67 },
  skin_golden_empress: { accent: 0xffd56a, trim: 0xfff1a8, jacket: 0x76502f },
};
const TRAIL_COLORS = {
  trail_neon_pink: 0xff78be,
  trail_cyber_lightning: 0x76efff,
  trail_solar_gold: 0xffd86a,
};
const isCoarse = window.matchMedia("(pointer: coarse)").matches;
const keyState = new Set();
const touchInput = { x: 0, z: 0 };
const playerState = {
  x: 31,
  z: 79,
  y: 0,
  vy: 0,
  direction: new THREE.Vector3(0, 0, -1),
  dashTime: 0,
  jumpOffset: 0,
};
const animated = [];
const worldColliders = [];
const projectiles = [];
const enemies = [];
const enemyProjectiles = [];
const combatDrops = [];
const telegraphs = [];
const dashTrailMeshes = [];
const collectibleMeshes = new Map();
const beaconMeshes = new Map();
const gameState = {
  csrfToken: "",
  authenticated: false,
  busy: false,
  progress: {
    xp: 0,
    level: 1,
    unlocked_collectibles: [],
    discovered_zones: [],
    total_adventures_completed: 0,
    last_position: null,
  },
  adventure: { attempts_remaining_today: 0, free_attempts_per_day: 3, reward_amount_coins: 50, active_session: null },
  session: null,
  codex: [],
  shop: { catalog: [], owned: [], loadout: { skin: null, weapon: null, pet: null, trail: null }, wallet: { earned: 0, purchased: 0, total: 0 } },
  shopCategory: "weapons",
  actionQueue: Promise.resolve(),
  serverZone: "neon_pier",
  interaction: null,
  selectedWeapon: "wand",
  unlockedWeapons: new Set(["wand"]),
  hp: 100,
  maxHp: 100,
  shield: 50,
  maxShield: 50,
  invulnerableUntil: 0,
  knockedDownUntil: 0,
  lastFireAt: 0,
  lastDamageAt: 0,
  activeBossPattern: false,
  muted: false,
  audioContext: null,
  equippedPet: null,
  petLoadVersion: 0,
  lastTrailAt: 0,
};
let renderer;
let camera;
let scene;
let player;
let aura;
let weapon;
let avatarFallback;
let playerMixer;
let playerActions = {};
let playerCurrentAction = null;
let playerJumpAction = null;
let playerHasJumpClip = false;
let playerActionUntil = 0;
let equippedPetActor = null;
let frameHandle = 0;
let lastFrame = 0;
let activeZone = "";
let activeZoneId = "neon_pier";
let languageOverride = null;
let cameraYaw = 0;
let cameraPitch = DEFAULT_CAMERA_PITCH;
let cameraPointer = null;
let cameraTurnKeyStartedAt = 0;
let messageTimer = 0;
let supplyCrate = null;
let lastSupplyAt = 0;
let knockdownMessageUntil = 0;

const loader = new GLTFLoader();
const GLTF_LOAD_TIMEOUT_MS = 6000;
const material = (color, roughness = 0.86, extra = {}) => new THREE.MeshStandardMaterial({ color, roughness, ...extra });

function loadGLTFWithTimeout(url) {
  let timeoutId;
  const timeout = new Promise((_, reject) => {
    timeoutId = window.setTimeout(
      () => reject(new Error(`Timed out loading ${url} after ${GLTF_LOAD_TIMEOUT_MS} ms.`)),
      GLTF_LOAD_TIMEOUT_MS,
    );
  });
  return Promise.race([loader.loadAsync(url), timeout]).finally(() => window.clearTimeout(timeoutId));
}

const mats = {
  grass: material(0x78c850),
  sand: material(0xf3d59b),
  cliff: material(0x9b7756),
  path: material(0xe7cfa0),
  wood: material(0xa96b3e),
  paleWood: material(0xc58a55),
  stone: material(0xdfc79e),
  darkStone: material(0x927759),
  roof: material(0xe25845),
  gold: material(0xf2d08a, 0.48, { metalness: 0.16, emissive: 0x74501d, emissiveIntensity: 0.2 }),
  cream: material(0xf2e2c4),
  blue: material(0x45caff, 0.34, { emissive: 0x087ba8, emissiveIntensity: 0.46 }),
  red: material(0xe88c6b),
  leaf: material(0x4d9d43),
  leafLight: material(0x82c94c),
  flower: material(0xff8192, 0.56, { emissive: 0x782e38, emissiveIntensity: 0.12 }),
  dark: material(0x604a39),
  white: material(0xfff9e9, 0.7),
};

function showMessage(text, duration = 2600) {
  message.textContent = text;
  messageTimer = duration;
}

function isArabic() {
  if (languageOverride) return languageOverride === "ar";
  const globalLanguage = window.NEEN_LANGUAGE?.current;
  if (globalLanguage === "ar" || globalLanguage === "en") return globalLanguage === "ar";
  try {
    return window.localStorage.getItem("neen-language") === "ar";
  } catch {
    return false;
  }
}

function requestKey(prefix) {
  return globalThis.crypto?.randomUUID
    ? globalThis.crypto.randomUUID()
    : `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 12)}`;
}

async function getCsrfToken() {
  if (gameState.csrfToken) return gameState.csrfToken;
  const response = await fetch("/api/auth/csrf", {
    headers: { Accept: "application/json" },
    credentials: "same-origin",
    cache: "no-store",
  });
  let result;
  try {
    result = await response.json();
  } catch {
    throw new Error("The security token could not be read.");
  }
  if (!response.ok || result.success !== true || !result.data?.csrf_token) {
    throw new Error(result.errors?.[0]?.message || "A security token is required.");
  }
  gameState.csrfToken = result.data.csrf_token;
  return gameState.csrfToken;
}

async function api(path, method = "GET", data = null) {
  const headers = { Accept: "application/json" };
  const options = { method, credentials: "same-origin", cache: "no-store", headers };
  if (data !== null) {
    headers["Content-Type"] = "application/json";
    headers["X-CSRF-Token"] = await getCsrfToken();
    options.body = JSON.stringify(data);
  }
  const response = await fetch(path, options);
  let result;
  try {
    result = await response.json();
  } catch {
    throw new Error("The island service returned an unreadable response.");
  }
  if (!response.ok || result.success !== true) {
    const error = new Error(result.errors?.[0]?.message || "The island request could not be completed.");
    error.code = result.errors?.[0]?.code;
    error.status = response.status;
    throw error;
  }
  return result.data;
}

const TEXT = {
  en: {
    exploring: "YOU ARE EXPLORING",
    codex: "Codex",
    leaderboard: "Top Explorers",
    shop: "Island Shop",
    soundOn: "Sound On",
    soundOff: "Sound Off",
    hp: "HP",
    shield: "Shield",
    adventure: "ISLAND ADVENTURE",
    freeRoam: "Free Roam",
    start: "Start Daily Adventure",
    inProgress: "Adventure in Progress",
    complete: "Complete Adventure",
    objectives: "Start an adventure to reveal your three server-assigned objectives.",
    signIn: "Sign in to save discoveries and start an adventure.",
    daily: "Daily adventures",
    loading: "Loading…",
    notFound: "Not found yet",
    found: "Discovered",
    buy: "Buy",
    equip: "Equip",
    equipped: "Equipped",
    insufficient: "Get NEEN Coins ↗",
    coins: "Coins",
    weapons: "Weapons",
    skins: "Skins",
    pets: "Pets",
    trails: "Dash Trails",
    level: "Level",
    xp: "XP",
    completed: "completed",
    action: "Action",
    noExplorers: "No island explorers yet.",
    topTen: "Top 10 island explorers",
    shopHeading: "Island",
    shopSuffix: "Shop",
    codexHeading: "Island",
    codexSuffix: "Codex",
    leaderboardHeading: "Top",
    leaderboardSuffix: "Explorers",
    shopClose: "Close Island Shop",
    codexClose: "Close Codex",
    leaderboardClose: "Close leaderboard",
    collectionEyebrow: "YOUR ISLAND COLLECTION",
    controlHint: "WASD move · SPACE jump · SHIFT dash · CLICK / F fire · drag / Q or E hold to orbit · R recenter · E tap action · 1–3 weapons",
    jump: "Jump",
    dash: "Dash",
    fire: "Fire",
    language: "العربية",
    titleEyebrow: "THE ISLAND, REIMAGINED",
    siteTitle: "Sunny Island",
    backTo2d: "2D Island",
    returnLabel: "Return to Sunny Island",
    canvasLabel: "Third-person 3D island. Use WASD or arrows to move; drag or swipe the right side, or hold Q/E, to orbit the camera; press R to recenter. Space jumps, Shift dashes, and click fires.",
    leaderboardEyebrow: "ISLAND ADVENTURE",
    codexEmpty: "Discover keepsakes while you explore.",
    discoveredCount: "discovered",
    undiscoveredAlt: "Undiscovered island keepsake",
    playerStatus: "Player health and shield",
    questLabel: "Daily adventure",
    weaponLabel: "Choose weapon",
    touchLabel: "Touch controls",
    joystickLabel: "Movement joystick",
    shopCategories: "Island Shop categories",
    purchased: "Purchased",
    earned: "Earned",
    leaderboardCosmetics: {
      skin_neon_crown: "Neon Crown",
      skin_sakura_bloom: "Sakura Bloom",
      skin_golden_empress: "Golden Empress",
      pet_snoop_classic: "Snoop Classic",
      pet_snoop_cyber: "Snoop Cyber",
    },
    zoneNames: ["Rim Nam Docks / Moonlit Bay", "Peak Ruins / Crownleaf Manor", "Factory / Skycrate Foundry", "Clock Tower Summit / Solstice Bellspire"],
  },
  ar: {
    exploring: "أنت تستكشف الآن",
    codex: "السجل",
    leaderboard: "أفضل المستكشفين",
    shop: "متجر الجزيرة",
    soundOn: "الصوت يعمل",
    soundOff: "الصوت مغلق",
    hp: "الصحة",
    shield: "الدرع",
    adventure: "مغامرة الجزيرة",
    freeRoam: "تجوال حر",
    start: "ابدأ مغامرة اليوم",
    inProgress: "المغامرة جارية الآن",
    complete: "إكمال المغامرة",
    objectives: "ابدأ مغامرة لكشف أهدافك الثلاثة من الخادم.",
    signIn: "سجّل الدخول لحفظ الاكتشافات وبدء مغامرة.",
    daily: "المغامرات اليومية",
    loading: "جارٍ التحميل…",
    notFound: "لم يُعثر عليه بعد",
    found: "تم اكتشافه",
    buy: "شراء",
    equip: "تجهيز",
    equipped: "مجهز",
    insufficient: "احصل على كوينز نين ↗",
    coins: "كوينز",
    weapons: "الأسلحة",
    skins: "المظاهر",
    pets: "الحيوانات الأليفة",
    trails: "مسارات الاندفاع",
    level: "المستوى",
    xp: "خبرة",
    completed: "مكتملة",
    action: "تفاعل",
    noExplorers: "لا يوجد مستكشفون بعد.",
    topTen: "أفضل 10 مستكشفين",
    shopHeading: "متجر",
    shopSuffix: "الجزيرة",
    codexHeading: "سجل",
    codexSuffix: "الجزيرة",
    leaderboardHeading: "أفضل",
    leaderboardSuffix: "المستكشفين",
    shopClose: "إغلاق متجر الجزيرة",
    codexClose: "إغلاق السجل",
    leaderboardClose: "إغلاق لوحة المتصدرين",
    collectionEyebrow: "مقتنيات جزيرتك",
    controlHint: "WASD حركة · SPACE قفز · SHIFT اندفاع · CLICK / F إطلاق · اسحب / Q أو اضغط E مطولاً لتدوير الكاميرا · R للتوسيط · E للتفاعل · 1–3 أسلحة",
    jump: "قفز",
    dash: "اندفاع",
    fire: "إطلاق",
    language: "English",
    titleEyebrow: "الجزيرة بحلة جديدة",
    siteTitle: "جزيرة الشمس",
    backTo2d: "الجزيرة ثنائية الأبعاد",
    returnLabel: "العودة إلى جزيرة الشمس",
    canvasLabel: "جزيرة ثلاثية الأبعاد بمنظور الشخص الثالث. استخدم WASD أو الأسهم للحركة؛ اسحب أو مرر على يمين الشاشة، أو اضغط Q أو E مطولاً، لتدوير الكاميرا، واضغط R لإعادتها. Space للقفز وShift للاندفاع والنقر لإطلاق النار.",
    leaderboardEyebrow: "مغامرة الجزيرة",
    codexEmpty: "اكتشف المقتنيات أثناء استكشافك.",
    discoveredCount: "مكتشف",
    undiscoveredAlt: "مقتنى غير مكتشف من الجزيرة",
    playerStatus: "صحة اللاعب والدرع",
    questLabel: "مغامرة اليوم",
    weaponLabel: "اختر سلاحاً",
    touchLabel: "أدوات التحكم باللمس",
    joystickLabel: "عصا الحركة",
    shopCategories: "فئات متجر الجزيرة",
    purchased: "مشتراة",
    earned: "مكتسبة",
    leaderboardCosmetics: {
      skin_neon_crown: "تاج النيون",
      skin_sakura_bloom: "أزهار ساكورا",
      skin_golden_empress: "الإمبراطورة الذهبية",
      pet_snoop_classic: "سنوب كلاسيك",
      pet_snoop_cyber: "سنوب سايبور",
    },
    zoneNames: ["أرصفة ريم نام / خليج القمر", "أطلال القمة / قصر أوراق التاج", "المصنع / مسبك صناديق السماء", "قمة برج الساعة / برج جرس الانقلاب"],
  },
};

function localizedZoneName(zoneId = activeZoneId) {
  const zoneIndex = ZONE_ORDER.indexOf(zoneId);
  return TEXT[isArabic() ? "ar" : "en"].zoneNames[zoneIndex] || ZONE_TITLES[zoneId] || "";
}

function localizeStaticLabels() {
  const ar = isArabic();
  const text = TEXT[ar ? "ar" : "en"];
  document.documentElement.lang = ar ? "ar" : "en";
  document.documentElement.dir = ar ? "rtl" : "ltr";
  activeZone = localizedZoneName();
  document.title = ar ? "جزيرة الشمس ثلاثية الأبعاد — نين" : "Sunny Island 3D — NEEN";
  document.querySelector(".title-block p").textContent = text.titleEyebrow;
  document.querySelector(".title-block h1").firstChild.textContent = `${text.siteTitle} `;
  document.querySelector(".back-link[href='sunny-island.html']").textContent = text.backTo2d;
  const returnLink = document.querySelector(".brand");
  returnLink.setAttribute("aria-label", text.returnLabel);
  canvas.setAttribute("aria-label", text.canvasLabel);
  document.querySelector("#language-toggle").textContent = text.language;
  document.querySelector("#language-toggle").setAttribute("aria-label", ar ? "Switch to English" : "التبديل إلى العربية");
  zoneLabel.textContent = localizedZoneName();
  document.querySelector(".zone-card small").textContent = text.exploring;
  document.querySelector("#open-codex").firstChild.textContent = `${text.codex} `;
  document.querySelector("#open-codex").setAttribute("aria-label", text.codex);
  document.querySelector("#open-leaderboard").textContent = text.leaderboard;
  document.querySelector("#open-shop").firstChild.textContent = `${text.shop} `;
  document.querySelector("#open-shop").setAttribute("aria-label", text.shop);
  document.querySelector("#open-leaderboard").setAttribute("aria-label", text.leaderboard);
  document.querySelector("#mute-toggle").textContent = gameState.muted ? text.soundOff : text.soundOn;
  document.querySelector(".quest-hud > small").textContent = text.adventure;
  document.querySelector("#adventure-start").textContent = text.start;
  document.querySelector("#adventure-complete").textContent = text.complete;
  document.querySelector(".touch-buttons [data-action=interact]").textContent = text.action;
  document.querySelector(".touch-buttons [data-action=jump]").textContent = text.jump;
  document.querySelector(".touch-buttons [data-action=dash]").textContent = text.dash;
  document.querySelector(".touch-buttons [data-action=fire]").textContent = text.fire;
  document.querySelector("#interaction-prompt [data-action=interact]").firstChild.textContent = `${text.action} `;
  document.querySelector(".controls-hint").textContent = text.controlHint;
  const shopTitle = document.querySelector("#shop-title");
  const codexTitle = document.querySelector("#codex-title");
  const leaderboardTitle = document.querySelector("#leaderboard-title");
  if (shopTitle?.firstChild) shopTitle.firstChild.textContent = `${text.shopHeading} `;
  if (codexTitle?.firstChild) codexTitle.firstChild.textContent = `${text.codexHeading} `;
  if (leaderboardTitle?.firstChild) leaderboardTitle.firstChild.textContent = `${text.leaderboardHeading} `;
  shopTitle?.querySelector("span")?.replaceChildren(document.createTextNode(text.shopSuffix));
  codexTitle?.querySelector("span")?.replaceChildren(document.createTextNode(text.codexSuffix));
  leaderboardTitle?.querySelector("span")?.replaceChildren(document.createTextNode(text.leaderboardSuffix));
  document.querySelector(".player-status").setAttribute("aria-label", text.playerStatus);
  document.querySelector(".quest-hud").setAttribute("aria-label", text.questLabel);
  document.querySelector(".weapon-picker").setAttribute("aria-label", text.weaponLabel);
  document.querySelector(".touch-controls").setAttribute("aria-label", text.touchLabel);
  document.querySelector("#joystick").setAttribute("aria-label", text.joystickLabel);
  document.querySelector(".shop-tabs").setAttribute("aria-label", text.shopCategories);
  document.querySelector("#shop-dialog .dialog-heading small").textContent = ar ? "كوينز نين" : "NEEN COINS";
  document.querySelector("#codex-dialog .dialog-heading small").textContent = text.collectionEyebrow;
  document.querySelector("#leaderboard-dialog .dialog-heading small").textContent = text.leaderboardEyebrow;
  document.querySelector("#codex-summary").textContent = text.codexEmpty;
  document.querySelector("[data-close='shop-dialog']").setAttribute("aria-label", text.shopClose);
  document.querySelector("[data-close='codex-dialog']").setAttribute("aria-label", text.codexClose);
  document.querySelector("[data-close='leaderboard-dialog']").setAttribute("aria-label", text.leaderboardClose);
  document.querySelectorAll(".zone-legend span").forEach((item, index) => {
    const textNode = [...item.childNodes].find((node) => node.nodeType === Node.TEXT_NODE);
    if (textNode) textNode.textContent = text.zoneNames[index] ? ` ${text.zoneNames[index]}` : "";
  });
  const walletLabels = document.querySelector("#shop-dialog .shop-wallet small");
  if (walletLabels?.childNodes[1] && walletLabels.childNodes[3]) {
    walletLabels.childNodes[1].textContent = ` ${text.purchased} · `;
    walletLabels.childNodes[3].textContent = ` ${text.earned}`;
  }
  const tabs = { weapons: text.weapons, skins: text.skins, pets: text.pets, trails: text.trails };
  document.querySelectorAll("[data-shop-tab]").forEach((tab) => {
    const category = tab.getAttribute("data-shop-tab");
    if (category) tab.textContent = tabs[category];
  });
  const weaponLabels = ar
    ? { wand: "1 عصا", mp40: "2 إم بي 40", ak47: "3 آي كيه 47" }
    : { wand: "1 Wand", mp40: "2 MP40", ak47: "3 AK-47" };
  document.querySelectorAll("[data-weapon]").forEach((button) => {
    const weaponId = button.getAttribute("data-weapon");
    if (weaponId && weaponLabels[weaponId]) button.textContent = weaponLabels[weaponId];
  });
  updateHud();
  if (document.querySelector("#shop-dialog").open) renderShop();
  if (document.querySelector("#codex-dialog").open) renderCodex();
  if (document.querySelector("#leaderboard-dialog").open) void refreshLeaderboard();
}

function addMesh(parent, geometry, meshMaterial, position, scale = null) {
  const mesh = new THREE.Mesh(geometry, meshMaterial);
  mesh.position.set(position[0], position[1], position[2]);
  if (scale) mesh.scale.set(scale[0], scale[1], scale[2]);
  mesh.castShadow = false;
  mesh.receiveShadow = false;
  parent.add(mesh);
  return mesh;
}

function box(parent, size, position, meshMaterial, rotationY = 0) {
  const mesh = addMesh(parent, new THREE.BoxGeometry(...size), meshMaterial, position);
  mesh.rotation.y = rotationY;
  return mesh;
}

function addWorldCollider(x, z, radius, options = {}) {
  worldColliders.push({ x, z, radius, ...options });
}

function addPath(start, end, width, meshMaterial, y = 0.58) {
  const dx = end.x - start.x;
  const dz = end.z - start.z;
  const length = Math.hypot(dx, dz);
  const plank = addMesh(
    scene,
    new THREE.BoxGeometry(width, 0.18, length),
    meshMaterial,
    [(start.x + end.x) / 2, y, (start.z + end.z) / 2],
  );
  plank.rotation.y = Math.atan2(dx, dz);
  return plank;
}

function setupRenderer() {
  try {
    renderer = new THREE.WebGLRenderer({
      canvas,
      antialias: !isCoarse,
      alpha: false,
      powerPreference: "high-performance",
    });
  } catch (error) {
    showMessage("WebGL could not start in this browser. Try a browser with WebGL enabled.", 0);
    console.error("Sunny Island 3D could not create a WebGL renderer.", error);
    return false;
  }
  renderer.outputColorSpace = THREE.SRGBColorSpace;
  renderer.toneMapping = THREE.ACESFilmicToneMapping;
  renderer.toneMappingExposure = 1.12;
  resizeRenderer();
  return true;
}

function resizeRenderer() {
  if (!renderer) return;
  const bounds = canvas.getBoundingClientRect();
  const dprCap = isCoarse ? 1.1 : 1.5;
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, dprCap));
  renderer.setSize(Math.max(1, bounds.width), Math.max(1, bounds.height), false);
  if (camera) {
    camera.aspect = Math.max(1, bounds.width) / Math.max(1, bounds.height);
    camera.updateProjectionMatrix();
  }
}

function buildOcean() {
  const water = new THREE.Mesh(
    new THREE.PlaneGeometry(360, 250),
    new THREE.MeshBasicMaterial({ color: 0x14b8d4 }),
  );
  water.rotation.x = -Math.PI / 2;
  water.position.set(WORLD.width / 2, -2.35, WORLD.depth / 2);
  scene.add(water);

  const island = new THREE.Mesh(
    new THREE.CylinderGeometry(71, 79, 3, 56, 1, false),
    mats.cliff,
  );
  island.position.set(WORLD.width / 2, -1, WORLD.depth / 2);
  scene.add(island);

  const beach = new THREE.Mesh(new THREE.CircleGeometry(71, 64), mats.sand);
  beach.rotation.x = -Math.PI / 2;
  beach.position.set(WORLD.width / 2, 0.51, WORLD.depth / 2);
  scene.add(beach);

  const islandTop = new THREE.Mesh(new THREE.CircleGeometry(65.5, 64), mats.grass);
  islandTop.rotation.x = -Math.PI / 2;
  islandTop.position.set(WORLD.width / 2, 0.55, WORLD.depth / 2);
  scene.add(islandTop);

  const islandRim = new THREE.Mesh(
    new THREE.TorusGeometry(65.5, 0.32, 5, 80),
    material(0x91cf59),
  );
  islandRim.rotation.x = Math.PI / 2;
  islandRim.position.set(WORLD.width / 2, 0.57, WORLD.depth / 2);
  scene.add(islandRim);

  for (let i = 0; i < 10; i += 1) {
    const angle = i * 0.59 + 0.2;
    const radius = 81 + (i % 3) * 2;
    const rockRadius = 2.2 + (i % 2) * 0.8;
    const x = 80 + Math.cos(angle) * radius;
    const z = 50 + Math.sin(angle) * radius * 0.68;
    const rock = new THREE.Mesh(
      new THREE.DodecahedronGeometry(rockRadius, 0),
      i % 2 ? mats.cliff : mats.darkStone,
    );
    rock.position.set(x, -0.75, z);
    rock.scale.y = 0.6;
    scene.add(rock);
    addWorldCollider(x, z, rockRadius);

    const foam = new THREE.Mesh(
      new THREE.TorusGeometry(rockRadius + 0.85, 0.12, 4, 18),
      new THREE.MeshBasicMaterial({ color: 0xf4ffff, transparent: true, opacity: 0.48 }),
    );
    foam.rotation.x = Math.PI / 2;
    foam.position.set(x, -1.72, z);
    scene.add(foam);
  }

  const sun = new THREE.Mesh(
    new THREE.SphereGeometry(7, 14, 10),
    new THREE.MeshBasicMaterial({ color: 0xffefb5 }),
  );
  sun.position.set(26, 38, -90);
  scene.add(sun);
}

function buildZonePlatforms() {
  const platformDimensions = [
    [27, 21],
    [28, 20],
    [28, 21],
    [28, 22],
  ];
  ZONES.forEach((zone, index) => {
    const [width, depth] = platformDimensions[index];
    const platform = new THREE.Mesh(
      new THREE.CylinderGeometry(1, 1.07, 0.4, 10),
      material(zone.color, 0.95),
    );
    platform.scale.set(width, 1, depth);
    platform.position.set(zone.x, 0.54, zone.z);
    platform.rotation.y = index * 0.13;
    scene.add(platform);

    const inset = new THREE.Mesh(
      new THREE.CylinderGeometry(1, 1, 0.12, 10),
      mats.sand,
    );
    inset.scale.set(width * 0.83, 1, depth * 0.83);
    inset.position.set(zone.x, 0.8, zone.z);
    inset.rotation.y = platform.rotation.y;
    scene.add(inset);
  });

  const routes = [
    [[35, 77], [38, 66], [44, 56], [51, 46], [58, 34]],
    [[58, 34], [73, 32], [86, 38], [98, 48], [108, 60], [118, 72]],
    [[118, 72], [122, 62], [125, 50], [125, 39], [123, 27]],
  ];
  for (const route of routes) {
    for (let index = 1; index < route.length; index += 1) {
      const [startX, startZ] = route[index - 1];
      const [endX, endZ] = route[index];
      addPath({ x: startX, z: startZ }, { x: endX, z: endZ }, 4.2, mats.path);
    }
  }
  addPath({ x: 35, z: 77 }, { x: 35, z: 88 }, 3.6, mats.path);
  addPath({ x: 35, z: 88 }, { x: 8, z: 88 }, 4.2, mats.wood);
}

function buildBayLandmark() {
  for (const [x, z] of [[10, 84], [19, 84], [29, 84], [39, 84], [52, 84], [10, 92], [19, 92], [29, 92], [39, 92], [52, 92]]) {
    addMesh(scene, new THREE.CylinderGeometry(0.16, 0.22, 1.9, 6), mats.wood, [x, -0.12, z]);
    addWorldCollider(x, z, 0.28);
  }
  for (const x of [10, 14, 18, 22, 26, 30, 34, 38, 42, 46]) {
    box(scene, [3.7, 0.22, 4.4], [x, 0.58, 88], mats.wood);
    box(scene, [0.1, 0.035, 4.2], [x + 1.82, 0.71, 88], mats.paleWood);
  }
  for (const [z, y] of [[83.2, 0.59], [84.5, 0.63]]) {
    box(scene, [4.2, 0.14, 1.2], [35, y, z], mats.sand);
  }

  for (const x of [25, 45]) {
    const lantern = new THREE.Group();
    addMesh(lantern, new THREE.CylinderGeometry(0.13, 0.2, 2.1, 6), mats.wood, [0, 1.05, 0]);
    addMesh(lantern, new THREE.BoxGeometry(0.72, 0.72, 0.72), mats.gold, [0, 2.35, 0]);
    addMesh(
      lantern,
      new THREE.BoxGeometry(0.48, 0.48, 0.48),
      material(0xffd77b, 0.36, { emissive: 0xffa53e, emissiveIntensity: 0.75 }),
      [0, 2.35, 0],
    );
    lantern.position.set(x, 0.53, 78);
    scene.add(lantern);
    addWorldCollider(x, 78, 0.62);
  }
}

function buildManorLandmark() {
  const x = 58;
  const z = 34;
  addMesh(scene, new THREE.CylinderGeometry(13, 13.7, 0.3, 40), mats.stone, [x, 0.59, z]);
  addMesh(scene, new THREE.CylinderGeometry(10.7, 11.3, 0.18, 40), mats.cream, [x, 0.82, z]);
  addMesh(scene, new THREE.TorusGeometry(11.9, 0.22, 5, 48), mats.gold, [x, 0.77, z]).rotation.x = Math.PI / 2;

  const columns = [
    [49, 24], [58, 20], [67, 24], [73, 28],
    [74, 45], [45, 45],
  ];
  for (const [pillarX, pillarZ] of columns) {
    addMesh(scene, new THREE.CylinderGeometry(0.56, 0.72, 5.3, 8), mats.cream, [pillarX, 3.3, pillarZ]);
    addMesh(scene, new THREE.CylinderGeometry(0.78, 0.78, 0.25, 8), mats.stone, [pillarX, 0.82, pillarZ]);
    addWorldCollider(pillarX, pillarZ, 0.82);
  }
  for (const [startX, startZ, endX, endZ] of [
    [49, 24, 58, 20], [58, 20, 67, 24], [67, 24, 73, 28],
    [73, 28, 74, 45], [74, 45, 45, 45], [45, 45, 49, 24],
  ]) {
    addPath({ x: startX, z: startZ }, { x: endX, z: endZ }, 0.7, mats.stone, 6.05);
  }
}

function buildFactoryLandmark() {
  for (const [x, z, width, depth] of [[101, 82, 12, 10], [143, 54, 12, 10], [93, 62, 10, 8]]) {
    box(scene, [width, 5.2, depth], [x, 2.9, z], mats.stone);
    addWorldCollider(x, z, Math.hypot(width / 2, depth / 2));
    for (const sign of [-1, 1]) {
      const roofPanel = box(
        scene,
        [width + 1, 0.28, depth * 0.72],
        [x, 5.9, z + sign * depth * 0.18],
        mats.roof,
      );
      roofPanel.rotation.x = sign * 0.36;
    }
    box(scene, [1.6, 2.5, 0.18], [x, 1.8, z - depth / 2 - 0.11], mats.wood);
    for (const windowX of [x - width * 0.3, x + width * 0.3]) {
      box(scene, [1.3, 1.2, 0.16], [windowX, 3.5, z - depth / 2 - 0.12], mats.blue);
      box(scene, [1.5, 0.12, 0.2], [windowX, 3.5, z - depth / 2 - 0.22], mats.cream);
    }
  }

  const crateColors = [mats.wood, mats.paleWood, mats.red];
  [[110, 76], [115, 78], [110, 82], [136, 65], [136, 73]].forEach(([x, z], index) => {
    box(scene, [4.6, 3.2, 3.6], [x, 2.1, z], crateColors[index % crateColors.length]);
    box(scene, [4.7, 0.18, 0.18], [x, 2.1, z - 1.85], mats.cream);
    addWorldCollider(x, z, Math.hypot(2.3, 1.8));
  });
}

function buildClockTower() {
  const x = 123;
  const z = 27;
  addMesh(scene, new THREE.CylinderGeometry(22, 22.8, 0.24, 56), mats.sand, [x, 0.59, z]);
  for (const [radius, ringMaterial, y] of [
    [20.4, mats.gold, 0.76], [15.5, mats.red, 0.78], [10.5, mats.gold, 0.8],
  ]) {
    const ring = addMesh(scene, new THREE.TorusGeometry(radius, 0.34, 5, 56), ringMaterial, [x, y, z]);
    ring.rotation.x = Math.PI / 2;
  }

  const towerZ = 8;
  addMesh(scene, new THREE.CylinderGeometry(5.5, 6.4, 1.2, 8), mats.stone, [x, 1.25, towerZ]);
  addMesh(scene, new THREE.CylinderGeometry(4.4, 4.8, 10, 8), mats.cream, [x, 6.8, towerZ]);
  addMesh(scene, new THREE.CylinderGeometry(4.8, 4.8, 0.6, 8), mats.gold, [x, 11.8, towerZ]);
  addWorldCollider(x, towerZ, 6.4);
  const roof = addMesh(scene, new THREE.ConeGeometry(5.6, 5.4, 8), mats.roof, [x, 14.6, towerZ]);
  roof.rotation.y = Math.PI / 8;

  const face = addMesh(scene, new THREE.CircleGeometry(2.35, 24), mats.cream, [x, 7.4, towerZ - 4.16]);
  face.rotation.x = 0;
  const rim = addMesh(scene, new THREE.TorusGeometry(2.48, 0.22, 7, 24), mats.gold, [x, 7.4, towerZ - 4.2]);
  rim.rotation.x = 0;
  box(scene, [0.18, 1.6, 0.12], [x, 7.8, towerZ - 4.38], mats.dark);
  box(scene, [1.1, 0.18, 0.12], [x + 0.45, 7.05, towerZ - 4.38], mats.dark);

  for (const [pillarX, pillarZ] of [[109, 13], [137, 13], [109, 41], [137, 41]]) {
    addMesh(scene, new THREE.CylinderGeometry(0.72, 0.88, 5.2, 8), mats.stone, [pillarX, 3.1, pillarZ]);
    addMesh(scene, new THREE.SphereGeometry(0.72, 10, 8), mats.gold, [pillarX, 5.9, pillarZ]);
    addMesh(scene, new THREE.OctahedronGeometry(0.94, 0), mats.blue, [pillarX, 6.45, pillarZ]);
    addWorldCollider(pillarX, pillarZ, 0.9);
  }
}

function createProceduralTree(position, variant = 0) {
  addWorldCollider(position[0], position[1], 0.52);
  const tree = new THREE.Group();
  const trunk = addMesh(tree, new THREE.CylinderGeometry(0.28, 0.48, 3.1, 6), mats.wood, [0, 1.45, 0]);
  trunk.rotation.z = variant % 2 ? 0.08 : 0;
  const foliage = variant % 2 ? mats.leafLight : mats.leaf;
  for (let i = 0; i < 3; i += 1) {
    addMesh(
      tree,
      new THREE.IcosahedronGeometry(1.45 + (i % 2) * 0.3, 1),
      foliage,
      [Math.sin(i * 2.1) * 0.55, 3.1 + i * 0.8, Math.cos(i * 1.8) * 0.48],
    );
  }
  tree.position.set(position[0], 0.53, position[1]);
  scene.add(tree);
  return tree;
}

function createProceduralBush(position, variant = 0) {
  addWorldCollider(position[0], position[1], 1.35, { jumpable: true, jumpClearance: 0.82 });
  const bush = new THREE.Group();
  for (let i = 0; i < 3; i += 1) {
    addMesh(
      bush,
      new THREE.IcosahedronGeometry(0.82 + (i % 2) * 0.12, 1),
      variant % 2 ? mats.leafLight : mats.leaf,
      [i * 0.74 - 0.74, 0.55 + (i % 2) * 0.12, 0],
    );
  }
  for (const [x, y, z] of [[-0.42, 1.16, -0.3], [0.22, 1.25, -0.34], [0.78, 1.08, -0.2]]) {
    addMesh(bush, new THREE.SphereGeometry(0.16, 7, 5), mats.flower, [x, y, z]);
  }
  bush.position.set(position[0], 0.52, position[1]);
  scene.add(bush);
  return bush;
}

function normalizeStaticModel(source, targetHeight) {
  const model = source.clone(true);
  const bounds = new THREE.Box3().setFromObject(model);
  const size = bounds.getSize(new THREE.Vector3());
  const scale = targetHeight / Math.max(size.y, 0.1);
  const center = bounds.getCenter(new THREE.Vector3());
  model.scale.multiplyScalar(scale);
  model.position.set(-center.x * scale, -bounds.min.y * scale, -center.z * scale);
  return model;
}

function placeAssetNature(gltf, positions, targetHeight) {
  const sourceVariants = gltf.scene.children.filter((child) => child.isMesh || child.children.length);
  if (sourceVariants.length === 0) throw new Error("Nature model contains no visible objects.");
  positions.forEach((position, index) => {
    const isBush = targetHeight <= 2.5;
    addWorldCollider(position[0], position[1], isBush ? 1.35 : 0.95, isBush
      ? { jumpable: true, jumpClearance: 0.82 }
      : {});
    const source = sourceVariants[index % sourceVariants.length];
    const prop = normalizeStaticModel(source, targetHeight * (0.82 + (index % 3) * 0.1));
    prop.position.add(new THREE.Vector3(position[0], 0.54, position[1]));
    scene.add(prop);
    if (isBush) {
      for (const [x, y, z] of [[-0.45, 1.3, -0.45], [0.2, 1.55, -0.5], [0.72, 1.2, -0.28]]) {
        addMesh(prop, new THREE.SphereGeometry(0.18, 7, 5), mats.flower, [x, y, z]);
      }
    }
  });
}

function createFallbackCreature(position, kind) {
  const fallback = new THREE.Group();
  const main = kind === "flying" ? mats.blue : kind === "enemy" ? mats.red : mats.wood;
  const body = addMesh(fallback, new THREE.SphereGeometry(1, 12, 9), main, [0, 1.2, 0], [0.72, 0.85, 0.95]);
  addMesh(fallback, new THREE.SphereGeometry(0.58, 12, 9), mats.cream, [0, 2.2, -0.12]);
  addMesh(fallback, new THREE.SphereGeometry(0.12, 8, 6), mats.dark, [-0.23, 2.25, -0.62]);
  addMesh(fallback, new THREE.SphereGeometry(0.12, 8, 6), mats.dark, [0.23, 2.25, -0.62]);
  for (const x of [-0.4, 0.4]) {
    addMesh(fallback, new THREE.CylinderGeometry(0.08, 0.1, 0.82, 6), main, [x, 0.42, 0]);
  }
  body.userData.originY = body.position.y;
  fallback.position.set(position[0], 0.53, position[1]);
  scene.add(fallback);
  return fallback;
}

function chooseIdleClip(clips) {
  return clips.find((clip) => /idle|flying/i.test(clip.name)) || clips[0];
}

async function loadAnimatedModel(url, position, targetHeight, kind) {
  try {
    const gltf = await loadGLTFWithTimeout(url);
    const model = gltf.scene;
    const bounds = new THREE.Box3().setFromObject(model);
    const size = bounds.getSize(new THREE.Vector3());
    const scale = targetHeight / Math.max(size.y, 0.1);
    const center = bounds.getCenter(new THREE.Vector3());
    model.scale.multiplyScalar(scale);
    model.position.set(-center.x * scale, -bounds.min.y * scale, -center.z * scale);

    const actor = new THREE.Group();
    actor.add(model);
    actor.position.set(position[0], kind === "flying" ? 2.4 : 0.53, position[1]);
    scene.add(actor);

    const mixer = new THREE.AnimationMixer(model);
    const idle = chooseIdleClip(gltf.animations);
    const walk = gltf.animations.find((clip) => /walk|run/i.test(clip.name));
    const idleAction = idle ? mixer.clipAction(idle) : null;
    const walkAction = walk ? mixer.clipAction(walk) : null;
    idleAction?.play();
    animated.push({
      mixer,
      actor,
      kind,
      position: new THREE.Vector3(position[0], actor.position.y, position[1]),
      idleAction,
      walkAction,
      activeAction: idleAction,
    });
    return actor;
  } catch (error) {
    console.error(`Could not load local ${kind} model (${url}).`, error);
    const actor = createFallbackCreature(position, kind);
    animated.push({
      mixer: null,
      actor,
      kind,
      position: new THREE.Vector3(position[0], actor.position.y, position[1]),
      idleAction: null,
      walkAction: null,
      activeAction: null,
    });
    showMessage(`A ${kind} model was unavailable; using its built-in 3D fallback.`, 4200);
    return actor;
  }
}

function makeCharacter() {
  const character = new THREE.Group();
  avatarFallback = new THREE.Group();
  character.add(avatarFallback);
  const skin = material(0xf4c59b);
  const outfit = material(0x20a9bd);
  const outfitLight = material(0x7be6d6);
  const hair = material(0x3d2734);
  const crown = material(0xffd15e, 0.35, { metalness: 0.3, emissive: 0x6a3b00, emissiveIntensity: 0.28 });

  addMesh(avatarFallback, new THREE.CapsuleGeometry(0.72, 1.15, 3, 8), outfit, [0, 2.05, 0]);
  addMesh(avatarFallback, new THREE.SphereGeometry(0.78, 16, 12), skin, [0, 3.55, -0.02]);
  addMesh(avatarFallback, new THREE.SphereGeometry(0.82, 16, 10), hair, [0, 3.9, 0.12], [1.05, 0.75, 1.05]);
  addMesh(avatarFallback, new THREE.SphereGeometry(0.65, 16, 12), skin, [0, 3.5, -0.35], [0.94, 1, 0.72]);

  for (const x of [-0.29, 0.29]) {
    addMesh(avatarFallback, new THREE.SphereGeometry(0.095, 8, 6), mats.dark, [x, 3.63, -0.94]);
    addMesh(avatarFallback, new THREE.SphereGeometry(0.045, 7, 5), mats.white, [x - 0.018, 3.66, -0.985]);
  }
  const leftArm = addMesh(avatarFallback, new THREE.CapsuleGeometry(0.24, 0.87, 2, 7), outfitLight, [-0.96, 2.05, -0.03]);
  const rightArm = addMesh(avatarFallback, new THREE.CapsuleGeometry(0.24, 0.87, 2, 7), outfitLight, [0.96, 2.05, -0.03]);
  leftArm.rotation.z = -0.38;
  rightArm.rotation.z = 0.38;
  avatarFallback.userData.arms = [leftArm, rightArm];
  avatarFallback.userData.legs = [];
  for (const x of [-0.39, 0.39]) {
    const leg = addMesh(avatarFallback, new THREE.CapsuleGeometry(0.27, 0.98, 2, 7), material(0x37536e), [x, 0.88, 0.04]);
    avatarFallback.userData.legs.push(leg);
    addMesh(avatarFallback, new THREE.BoxGeometry(0.68, 0.34, 1), mats.dark, [x, 0.28, -0.13]);
  }

  const crownBand = addMesh(avatarFallback, new THREE.TorusGeometry(0.76, 0.11, 8, 20), crown, [0, 4.18, 0]);
  crownBand.rotation.x = Math.PI / 2;
  for (let i = 0; i < 5; i += 1) {
    const angle = -Math.PI * 0.85 + i * Math.PI * 0.42;
    const spike = addMesh(
      avatarFallback,
      new THREE.ConeGeometry(0.18, 0.72, 5),
      crown,
      [Math.sin(angle) * 0.65, 4.58 + (i % 2) * 0.12, Math.cos(angle) * 0.42],
    );
    spike.rotation.z = Math.sin(angle) * 0.35;
  }

  aura = addMesh(
    character,
    new THREE.TorusGeometry(1.05, 0.075, 7, 28),
    material(0x9cf7ff, 0.45, { emissive: 0x25aabc, emissiveIntensity: 0.65 }),
    [0, 0.17, 0],
  );
  aura.rotation.x = Math.PI / 2;

  weapon = new THREE.Group();
  weapon.position.set(0.92, 2.25, -0.52);
  weapon.rotation.x = -0.2;
  character.add(weapon);
  buildHeldWeapon("wand");

  character.position.set(playerState.x, GROUND_Y, playerState.z);
  scene.add(character);
  return character;
}

function buildHeldWeapon(type) {
  if (!weapon) return;
  weapon.clear();
  if (type === "mp40" || type === "ak47") {
    const color = type === "mp40" ? material(0x536d78, 0.48, { metalness: 0.56 }) : material(0x805b3d, 0.62, { metalness: 0.36 });
    const trim = material(WEAPON_STATS[type].color, 0.42, { metalness: 0.25, emissive: WEAPON_STATS[type].color, emissiveIntensity: 0.16 });
    box(weapon, [0.34, 0.36, 1.35], [0, 0, -0.18], color);
    box(weapon, [0.17, 0.2, 0.92], [0, 0.04, -1.12], trim);
    box(weapon, [0.22, 0.58, 0.3], [0, -0.39, 0.15], mats.dark);
    box(weapon, [0.16, 0.23, 0.48], [0, 0.28, -0.18], mats.gold);
    if (type === "ak47") box(weapon, [0.24, 0.68, 0.26], [0, -0.15, -0.25], color, -0.14);
    return;
  }
  const wand = addMesh(weapon, new THREE.CylinderGeometry(0.09, 0.13, 1.55, 7), mats.gold, [0, 0.1, 0]);
  wand.rotation.z = -0.22;
  addMesh(weapon, new THREE.OctahedronGeometry(0.33, 0), mats.blue, [0.04, 0.98, 0]);
}

function applyPlayerSkin() {
  if (!aura) return;
  const skin = SKIN_PALETTES[gameState.shop.loadout.skin];
  aura.material.color.setHex(skin?.accent ?? 0x9cf7ff);
  aura.material.emissive.setHex(skin?.accent ?? 0x25aabc);
  aura.material.needsUpdate = true;
  if (!player?.userData.avatarModel) return;
  player.userData.avatarModel.traverse((node) => {
    if (!node.isMesh) return;
    const materials = Array.isArray(node.material) ? node.material : [node.material];
    for (const meshMaterial of materials) {
      if (!meshMaterial || !meshMaterial.color) continue;
      if (/^(white|grey)$/i.test(meshMaterial.name)) meshMaterial.color.setHex(skin?.jacket ?? 0xffffff);
      if (/^orange$/i.test(meshMaterial.name) && skin) meshMaterial.color.setHex(skin.trim);
    }
  });
}

function playPlayerAnimation(name, fade = 0.18) {
  const action = playerActions[name];
  if (!action || action === playerCurrentAction) return;
  playerCurrentAction?.fadeOut(fade);
  action.reset().fadeIn(fade).play();
  playerCurrentAction = action;
}

async function loadPlayerAvatar() {
  const url = "./assets/sunny-3d/models/neen-player.glb";
  try {
    const gltf = await loadGLTFWithTimeout(url);
    const model = gltf.scene;
    const bounds = new THREE.Box3().setFromObject(model);
    const size = bounds.getSize(new THREE.Vector3());
    const center = bounds.getCenter(new THREE.Vector3());
    const scale = 4.8 / Math.max(size.y, 0.1);
    const avatarRoot = new THREE.Group();
    model.scale.multiplyScalar(scale);
    model.position.set(-center.x * scale, -bounds.min.y * scale, -center.z * scale);
    avatarRoot.rotation.y = Math.PI;
    avatarRoot.add(model);
    player.add(avatarRoot);
    player.userData.avatarModel = avatarRoot;
    avatarFallback.visible = false;

    model.traverse((node) => {
      if (!node.isMesh) return;
      node.material = Array.isArray(node.material)
        ? node.material.map((entry) => entry.clone())
        : node.material.clone();
      node.frustumCulled = false;
    });

    playerMixer = new THREE.AnimationMixer(avatarRoot);
    const clips = gltf.animations;
    const idle = clips.find((clip) => /idle_neutral/i.test(clip.name))
      || clips.find((clip) => /idle/i.test(clip.name));
    const run = clips.find((clip) => /(^|[|_])run($|[|_])/i.test(clip.name))
      || clips.find((clip) => /walk/i.test(clip.name));
    const jumpClip = clips.find((clip) => /jump/i.test(clip.name));
    const shoot = clips.find((clip) => /gun_shoot|idle_gun_shoot/i.test(clip.name));
    if (idle) playerActions.idle = playerMixer.clipAction(idle);
    if (run) playerActions.run = playerMixer.clipAction(run);
    if (shoot) {
      playerActions.shoot = playerMixer.clipAction(shoot);
      playerActions.shoot.setLoop(THREE.LoopOnce, 1);
      playerActions.shoot.clampWhenFinished = true;
    }
    if (jumpClip) {
      playerActions.jump = playerMixer.clipAction(jumpClip);
      playerActions.jump.setLoop(THREE.LoopOnce, 1);
      playerActions.jump.clampWhenFinished = true;
      playerHasJumpClip = true;
    } else {
      const jumpScale = new THREE.VectorKeyframeTrack(
        ".scale",
        [0, 0.18, 0.42, 0.7],
        [1, 1, 1, 1.04, 1.12, 1.04, 0.96, 0.88, 0.96, 1, 1, 1],
      );
      const fallbackJump = new THREE.AnimationClip("ProceduralJump", 0.7, [jumpScale]);
      playerActions.jump = playerMixer.clipAction(fallbackJump);
      playerActions.jump.setLoop(THREE.LoopOnce, 1);
      playerActions.jump.clampWhenFinished = true;
    }
    if (idle) {
      playerActions.idle.play();
      playerCurrentAction = playerActions.idle;
    }
    applyPlayerSkin();
    showMessage(isArabic() ? "تم تحميل شخصية نين المتحركة." : "Animated NEEN character loaded.", 1800);
  } catch (error) {
    console.error("Could not load the local Animated Woman player model; using the procedural NEEN character.", error);
    showMessage(isArabic() ? "تعذر تحميل الشخصية المتحركة؛ تم تفعيل شخصية نين البديلة." : "The animated avatar could not load; using the procedural NEEN character.", 4200);
  }
}

function addEnemyHealthBar(enemy) {
  const bar = new THREE.Group();
  const width = enemy.boss ? 5.2 : 3.2;
  const height = 0.24;
  const backdrop = addMesh(bar, new THREE.BoxGeometry(width, height, 0.14), material(0x243b49), [0, 0, 0]);
  backdrop.material = new THREE.MeshBasicMaterial({ color: 0x243b49, transparent: true, opacity: 0.88 });
  enemy.healthFill = addMesh(
    bar,
    new THREE.BoxGeometry(width - 0.12, height * 0.58, 0.17),
    new THREE.MeshBasicMaterial({ color: enemy.boss ? 0xffcb58 : 0x72e0ba }),
    [0, 0, 0.1],
  );
  enemy.healthBarWidth = width - 0.12;
  bar.position.y = enemy.boss ? 8.6 : 6.1;
  enemy.root.add(bar);
}

function createEnemyVisual(enemy) {
  const visual = new THREE.Group();
  const mainMaterial = material(enemy.color, 0.58, { metalness: enemy.kind ? 0.32 : 0.05 });
  const accentMaterial = material(enemy.accent, 0.43, { emissive: enemy.accent, emissiveIntensity: 0.23 });
  if (enemy.kind === "small" || enemy.kind === "flying") {
    addMesh(visual, new THREE.OctahedronGeometry(enemy.kind === "flying" ? 1.15 : 0.9, 1), mainMaterial, [0, 1.8, 0]);
    addMesh(visual, new THREE.TorusGeometry(1.45, 0.09, 6, 14), accentMaterial, [0, 1.8, 0]).rotation.x = Math.PI / 2;
    for (const side of [-1, 1]) {
      addMesh(visual, new THREE.BoxGeometry(1.05, 0.12, 0.32), accentMaterial, [side * 1.1, 1.75, 0]);
    }
  } else {
    addMesh(visual, new THREE.IcosahedronGeometry(enemy.boss ? 2.45 : 1.8, 1), mainMaterial, [0, 2.15, 0]);
    addMesh(visual, new THREE.SphereGeometry(enemy.boss ? 1.15 : 0.88, 12, 8), accentMaterial, [0, 3.35, -0.3]);
    for (const side of [-1, 1]) {
      const horn = addMesh(visual, new THREE.ConeGeometry(0.52, enemy.boss ? 2.1 : 1.45, 5), accentMaterial, [side * 1.05, 4.25, 0]);
      horn.rotation.z = side * -0.45;
      addMesh(visual, new THREE.SphereGeometry(0.13, 8, 6), mats.dark, [side * 0.38, 3.47, -1.02]);
    }
    if (enemy.boss) {
      for (let i = 0; i < 6; i += 1) {
        const spike = addMesh(visual, new THREE.ConeGeometry(0.38, 1.4, 5), accentMaterial, [Math.cos(i) * 2.25, 2.35, Math.sin(i) * 2.25]);
        spike.rotation.z = Math.cos(i) * -0.42;
      }
    }
  }
  return visual;
}

function createCombatEnemy(definition) {
  const enemy = {
    ...definition,
    maxHp: definition.hp,
    alive: true,
    phase: Math.random() * Math.PI * 2,
    nextAttackAt: performance.now() + (definition.boss ? 2400 : 1600),
    nextSlamAt: performance.now() + 6200,
    root: new THREE.Group(),
    mixer: null,
    activeAction: null,
  };
  enemy.root.position.set(enemy.x, 0.53, enemy.z);
  enemy.visual = createEnemyVisual(enemy);
  enemy.root.add(enemy.visual);
  scene.add(enemy.root);
  addEnemyHealthBar(enemy);
  enemies.push(enemy);
  return enemy;
}

function clearGeneratedVisual(group) {
  group.traverse((node) => {
    if (node.isMesh) node.geometry.dispose();
  });
  group.clear();
}

async function loadEnemyModel(path, enemy, targetHeight) {
  try {
    const gltf = await loadGLTFWithTimeout(path);
    const model = gltf.scene;
    const bounds = new THREE.Box3().setFromObject(model);
    const size = bounds.getSize(new THREE.Vector3());
    const center = bounds.getCenter(new THREE.Vector3());
    const scale = targetHeight / Math.max(size.y, 0.1);
    model.scale.multiplyScalar(scale);
    model.position.set(-center.x * scale, -bounds.min.y * scale, -center.z * scale);
    clearGeneratedVisual(enemy.visual);
    enemy.root.remove(enemy.visual);
    enemy.visual = model;
    enemy.root.add(model);
    enemy.mixer = new THREE.AnimationMixer(model);
    const idle = enemy.kind === "flying"
      ? gltf.animations.find((clip) => /fly|flying|hover/i.test(clip.name)) || chooseIdleClip(gltf.animations)
      : chooseIdleClip(gltf.animations);
    if (idle) enemy.activeAction = enemy.mixer.clipAction(idle).play();
  } catch (error) {
    console.error(`Could not load local ${enemy.kind} enemy model (${path}); using its procedural fallback.`, error);
    showMessage(isArabic() ? "تعذر تحميل نموذج عدو؛ يستخدم شكلاً بديلاً." : "An enemy model could not load; using its procedural fallback.", 3600);
  }
}

function createWorldPickup(pickup) {
  const group = new THREE.Group();
  const color = pickup.id.includes("sticker") ? 0xf6a7d8 : pickup.id.includes("compass") ? 0xffd66e : 0x69e4d8;
  const itemMaterial = material(color, 0.32, { metalness: 0.18, emissive: color, emissiveIntensity: 0.28 });
  addMesh(group, new THREE.OctahedronGeometry(0.72, 0), itemMaterial, [0, 1.18, 0]);
  const ring = addMesh(group, new THREE.TorusGeometry(1.05, 0.07, 6, 20), mats.cream, [0, 0.2, 0]);
  ring.rotation.x = Math.PI / 2;
  group.position.set(pickup.x, 0.55, pickup.z);
  group.userData.baseY = group.position.y;
  group.userData.pickupId = pickup.id;
  group.userData.zone = pickup.zone;
  group.userData.baseX = pickup.x;
  group.userData.baseZ = pickup.z;
  group.userData.phase = Math.random() * 6;
  scene.add(group);
  collectibleMeshes.set(pickup.id, group);
}

function createBeacon(beacon) {
  const group = new THREE.Group();
  addWorldCollider(beacon.x, beacon.z, 0.72);
  addMesh(group, new THREE.CylinderGeometry(0.28, 0.44, 2.8, 8), mats.darkStone, [0, 1.45, 0]);
  const crystal = addMesh(
    group,
    new THREE.OctahedronGeometry(0.64, 0),
    material(0x92f4d4, 0.32, { emissive: 0x55d7b3, emissiveIntensity: 0.52 }),
    [0, 3.05, 0],
  );
  const halo = addMesh(group, new THREE.TorusGeometry(0.85, 0.08, 6, 18), mats.cream, [0, 3.05, 0]);
  halo.rotation.x = Math.PI / 2;
  group.position.set(beacon.x, 0.52, beacon.z);
  group.userData.crystal = crystal;
  group.userData.beaconId = beacon.id;
  group.userData.zone = beacon.zone;
  scene.add(group);
  beaconMeshes.set(beacon.id, group);
}

function buildSupplyCrate() {
  const root = new THREE.Group();
  box(root, [2.4, 2, 2.4], [0, 1.1, 0], mats.wood);
  box(root, [2.48, 0.18, 0.2], [0, 1.1, -1.22], mats.gold);
  box(root, [0.2, 2.1, 2.48], [0, 1.1, 0], mats.paleWood);
  root.position.set(127, 0.55, 61);
  scene.add(root);
  addWorldCollider(127, 61, 1.75);
  supplyCrate = { root, position: new THREE.Vector3(127, 0.55, 61), openedUntil: 0 };
}

function buildInteractiveWorld() {
  PICKUPS.forEach(createWorldPickup);
  BEACONS.forEach(createBeacon);
  buildSupplyCrate();
  GUARDIAN_DEFS.forEach(createCombatEnemy);
  const droneSmall = createCombatEnemy(DRONE_DEFS[0]);
  const droneFlying = createCombatEnemy(DRONE_DEFS[1]);
  return [
    loadEnemyModel("./assets/sunny-3d/models/enemy-small.glb", droneSmall, 3.1),
    loadEnemyModel("./assets/sunny-3d/models/enemy-flying.glb", droneFlying, 3.5),
  ];
}

function updateCollectibleVisibility() {
  const found = new Set(gameState.progress.unlocked_collectibles);
  for (const [id, group] of collectibleMeshes) group.visible = !found.has(id);
  const activated = new Set(gameState.session?.activated_beacons || []);
  for (const [id, group] of beaconMeshes) {
    const isActive = activated.has(id);
    group.userData.crystal.material.color.setHex(isActive ? 0x9be8ab : 0x92f4d4);
    group.userData.crystal.material.emissive.setHex(isActive ? 0x47845b : 0x55d7b3);
  }
}

function updateWorldInteractions(delta, time) {
  gameState.interaction = null;
  for (const [id, group] of collectibleMeshes) {
    if (!group.visible) continue;
    const phase = group.userData.phase;
    group.position.y = group.userData.baseY + Math.sin(time * 2.6 + phase) * 0.16;
    group.rotation.y += delta * 0.8;
    const item = PICKUPS.find((pickup) => pickup.id === id);
    const distance = Math.hypot(playerState.x - group.position.x, playerState.z - group.position.z);
    if (gameState.equippedPet && item?.zone === activeZoneId && distance < 11) {
      const strength = Math.min(1, delta * 1.4);
      group.position.x += (playerState.x - group.position.x) * strength;
      group.position.z += (playerState.z - group.position.z) * strength;
    }
    if (distance < 2.7 && gameState.session && item?.zone === activeZoneId) {
      gameState.interaction = { type: "collectible", item };
    }
  }
  for (const [id, group] of beaconMeshes) {
    group.userData.crystal.rotation.y += delta * 1.2;
    group.userData.crystal.position.y = 3.05 + Math.sin(time * 2.4 + (id.endsWith("a") ? 0 : 1.4)) * 0.13;
    if (activeZoneId === group.userData.zone
      && !gameState.session?.activated_beacons?.includes(id)
      && Math.hypot(playerState.x - group.position.x, playerState.z - group.position.z) < 3.1) {
      gameState.interaction = { type: "beacon", beacon: BEACONS.find((beacon) => beacon.id === id) };
    }
  }
  if (supplyCrate && performance.now() > supplyCrate.openedUntil
    && Math.hypot(playerState.x - supplyCrate.position.x, playerState.z - supplyCrate.position.z) < 3.7
    && activeZoneId === "loot_canyon") {
    gameState.interaction = { type: "supply" };
  }
  if (!gameState.interaction) {
    gameState.interaction = { type: "board" };
  }
  const prompt = document.querySelector("#interaction-prompt");
  const label = document.querySelector("#interaction-label");
  if (!prompt || !label) return;
  const active = gameState.interaction?.type === "collectible"
    || gameState.interaction?.type === "beacon"
    || gameState.interaction?.type === "supply";
  prompt.hidden = !active;
  if (gameState.interaction?.type === "collectible") {
    const collectible = gameState.codex.find((entry) => entry.id === gameState.interaction.item.id);
    label.textContent = isArabic()
      ? `اجمع ${collectible?.name || gameState.interaction.item.id}`
      : `Collect ${collectible?.name || gameState.interaction.item.id}`;
  } else if (gameState.interaction?.type === "beacon") {
    label.textContent = isArabic() ? "فعّل المنارة" : "Activate beacon";
  } else if (gameState.interaction?.type === "supply") {
    label.textContent = isArabic() ? "افتح صندوق الإمدادات" : "Open supply crate";
  }
}

function updateHud() {
  const ar = isArabic();
  const text = TEXT[ar ? "ar" : "en"];
  const hpLabel = document.querySelector("#hp-label");
  const shieldLabel = document.querySelector("#shield-label");
  const hpFill = document.querySelector("#hp-fill");
  const shieldFill = document.querySelector("#shield-fill");
  const questTitle = document.querySelector("#quest-title");
  const objectives = document.querySelector("#objectives");
  const startButton = document.querySelector("#adventure-start");
  const completeButton = document.querySelector("#adventure-complete");
  const attempts = document.querySelector("#attempts-label");

  if (hpLabel) hpLabel.textContent = `${text.hp} ${gameState.hp} / ${gameState.maxHp}`;
  if (shieldLabel) shieldLabel.textContent = `${text.shield} ${gameState.shield} / ${gameState.maxShield}`;
  if (hpFill) hpFill.style.width = `${100 * gameState.hp / gameState.maxHp}%`;
  if (shieldFill) shieldFill.style.width = `${100 * gameState.shield / gameState.maxShield}%`;

  const active = gameState.session && ["started", "in_progress"].includes(gameState.session.status);
  if (questTitle) questTitle.textContent = active ? (ar ? "مغامرة اليوم" : "Today's Adventure") : text.freeRoam;
  if (objectives) {
    objectives.replaceChildren();
    const assigned = Array.isArray(gameState.session?.objectives) ? gameState.session.objectives : [];
    if (!active || assigned.length === 0) {
      const empty = document.createElement("span");
      empty.textContent = gameState.authenticated ? text.objectives : text.signIn;
      objectives.append(empty);
    } else {
      for (const objective of assigned) {
        const row = document.createElement("span");
        row.className = `objective-row${objective.completed ? " is-complete" : ""}`;
        const check = document.createElement("b");
        check.className = "objective-check";
        check.textContent = objective.completed ? "✓" : "•";
        const description = document.createElement("span");
        description.textContent = localizedObjective(objective);
        row.append(check, description);
        objectives.append(row);
      }
    }
  }
  if (startButton) {
    startButton.hidden = Boolean(active);
    startButton.disabled = !gameState.authenticated || gameState.busy;
    startButton.textContent = text.start;
  }
  if (completeButton) {
    const allComplete = active
      && Array.isArray(gameState.session.objectives)
      && gameState.session.objectives.length > 0
      && gameState.session.objectives.every((objective) => objective.completed);
    completeButton.hidden = !allComplete;
    completeButton.disabled = gameState.busy || !allComplete
      || Date.now() - (gameState.sessionStartedAt || Date.now()) < 10000;
    completeButton.textContent = text.complete;
  }
  if (attempts) {
    attempts.textContent = gameState.authenticated
      ? `${text.daily}: ${gameState.adventure.attempts_remaining_today} / ${gameState.adventure.free_attempts_per_day}`
      : text.signIn;
  }
  const codexCount = document.querySelector("#codex-count");
  if (codexCount) {
    const total = gameState.codex.length || 9;
    codexCount.textContent = `${gameState.progress.unlocked_collectibles.length}/${total}`;
  }
  updateWeaponButtons();
  updateCollectibleVisibility();
}

function localizedObjective(objective) {
  if (!isArabic()) return objective.description || objective.id.replaceAll("_", " ");
  const translations = {
    collect_drops: "اجمع ثلاث قطرات شمسية في بستان النخيل.",
    activate_beacons: "فعّل المنارتين القديمتين في الرصيف المضيء.",
    reach_sunset_ridge: "اوصل إلى نقطة المراقبة في قمة الغروب.",
    find_relic: "اعثر على البوصلة الذهبية في وادي الغنائم.",
  };
  return translations[objective.id] || objective.description || objective.id;
}

function adoptServerState(data, reposition = false) {
  const snapshot = data.state || data;
  gameState.progress = snapshot.progress || gameState.progress;
  gameState.codex = Array.isArray(snapshot.codex) ? snapshot.codex : gameState.codex;
  gameState.adventure = snapshot.adventure || gameState.adventure;
  gameState.session = gameState.adventure.active_session || null;
  gameState.authenticated = true;
  const serverZone = gameState.session?.current_zone
    || gameState.progress.last_position?.zone
    || "neon_pier";
  if (ZONE_CENTERS[serverZone]) gameState.serverZone = serverZone;
  if (reposition && ZONE_CENTERS[serverZone]) {
    playerState.x = ZONE_CENTERS[serverZone].x;
    playerState.z = ZONE_CENTERS[serverZone].z;
    activeZoneId = serverZone;
    activeZone = localizedZoneName(serverZone);
    zoneLabel.textContent = activeZone;
  }
  const startedAt = gameState.session?.started_at;
  const startedTimestamp = typeof startedAt === "string"
    ? Date.parse(startedAt.includes("T") ? startedAt : `${startedAt.replace(" ", "T")}Z`)
    : NaN;
  gameState.sessionStartedAt = Number.isFinite(startedTimestamp) ? startedTimestamp : Date.now();
  updateHud();
}

async function loadServerState(reposition = true) {
  try {
    const result = await api("/api/games/sunny-island/state");
    adoptServerState(result, reposition);
    try {
      await refreshShop();
    } catch (error) {
      console.error("Could not load Island Shop state.", error);
      showMessage(error.message, 3500);
    }
    updateHud();
    showMessage(isArabic() ? "تم تحميل تقدم الجزيرة من حسابك." : "Island progress loaded from your account.", 2600);
  } catch (error) {
    gameState.authenticated = false;
    gameState.session = null;
    updateHud();
    if (error.code === "AUTH_REQUIRED") {
      showMessage(isArabic() ? "سجّل الدخول لحفظ تقدم الجزيرة ومكافآتها." : "Sign in to save island progress and rewards.", 0);
    } else {
      console.error("Could not load Sunny Island server state.", error);
      showMessage(error.message, 0);
    }
  }
}

function sendServerAction(action, targetId) {
  const operation = gameState.actionQueue.catch(() => {}).then(async () => {
    if (!gameState.authenticated || !gameState.session?.id) {
      throw new Error(isArabic() ? "ابدأ مغامرة يومية نشطة لحفظ هذا الإجراء." : "Start an active daily adventure to save this action.");
    }
    const nonce = gameState.session.action_nonce;
    if (typeof nonce !== "string" || nonce.length !== 43) {
      throw new Error("The current Sunny Island action nonce is unavailable.");
    }
    const result = await api("/api/games/sunny-island/action", "POST", {
      session_id: gameState.session.id,
      request_key: requestKey("sunny-action"),
      action_nonce: nonce,
      action,
      target_id: targetId,
    });
    if (result.progress) gameState.progress = result.progress;
    if (result.session) gameState.session = result.session;
    gameState.serverZone = result.session?.current_zone || gameState.serverZone;
    updateHud();
    return result;
  });
  gameState.actionQueue = operation.catch(() => {});
  return operation;
}

let zoneSyncing = false;
let requestedServerZone = null;
let zoneSyncPromise = null;

function syncServerZone(targetZone) {
  if (!gameState.authenticated || !gameState.session || !ZONE_ORDER.includes(targetZone)) return;
  requestedServerZone = targetZone;
  if (zoneSyncPromise) return zoneSyncPromise;
  zoneSyncing = true;
  zoneSyncPromise = (async () => {
    try {
      while (requestedServerZone && gameState.session) {
        const target = requestedServerZone;
        requestedServerZone = null;
        while (gameState.serverZone !== target) {
          const currentIndex = ZONE_ORDER.indexOf(gameState.serverZone);
          const targetIndex = ZONE_ORDER.indexOf(target);
          if (currentIndex < 0 || targetIndex < 0) throw new Error("The island zone route is invalid.");
          const nextZone = ZONE_ORDER[currentIndex + Math.sign(targetIndex - currentIndex)];
          await sendServerAction("enter_zone", nextZone);
        }
      }
    } catch (error) {
      console.error("Could not save Sunny Island zone discovery.", error);
      showMessage(error.message, 4200);
    } finally {
      zoneSyncing = false;
      zoneSyncPromise = null;
      if (requestedServerZone && gameState.session) void syncServerZone(requestedServerZone);
    }
  })();
  return zoneSyncPromise;
}

async function startAdventure() {
  if (!gameState.authenticated) {
    showMessage(isArabic() ? "سجّل الدخول لبدء مغامرة الجزيرة." : "Sign in to start a daily adventure.", 3000);
    return;
  }
  if (gameState.busy || gameState.session) return;
  gameState.busy = true;
  updateHud();
  try {
    const result = await api("/api/games/sunny-island/start", "POST", {
      idempotency_key: requestKey("sunny-start"),
    });
    gameState.session = result.session;
    gameState.adventure.attempts_remaining_today = result.attempts_remaining_today;
    gameState.adventure.reward_amount_coins = result.reward_amount_coins;
    gameState.serverZone = result.session.current_zone || "neon_pier";
    gameState.sessionStartedAt = Date.now();
    gameState.hp = gameState.maxHp;
    gameState.shield = gameState.maxShield;
    for (const enemy of enemies) {
      if (enemy.id.startsWith("guardian_")) {
        enemy.hp = enemy.maxHp;
        enemy.alive = true;
        enemy.root.visible = true;
        updateEnemyHealth(enemy);
      }
    }
    await syncServerZone(activeZoneId);
    updateHud();
    showMessage(isArabic() ? "بدأت مغامرتك اليومية — أكمل الأهداف الثلاثة." : "Daily Adventure started — complete all three objectives.", 3500);
    playSound("crate");
  } catch (error) {
    console.error("Could not start Sunny Island adventure.", error);
    showMessage(error.message, 4000);
  } finally {
    gameState.busy = false;
    updateHud();
  }
}

async function completeAdventure() {
  if (!gameState.session || gameState.busy) return;
  const nonce = gameState.session.action_nonce;
  if (typeof nonce !== "string" || nonce.length !== 43) {
    showMessage("The Sunny Island completion nonce is unavailable.", 3800);
    return;
  }
  gameState.busy = true;
  updateHud();
  try {
    const result = await api("/api/games/sunny-island/complete", "POST", {
      session_id: gameState.session.id,
      request_key: requestKey("sunny-complete"),
      completion_nonce: nonce,
    });
    gameState.progress = result.progress;
    gameState.session = null;
    gameState.adventure.active_session = null;
    showMessage(isArabic()
      ? `اكتملت المغامرة! +${result.xp_awarded} خبرة · +${result.reward.amount} كوينز.`
      : `Adventure complete! +${result.xp_awarded} XP · +${result.reward.amount} Earned Coins.`, 5200);
    await loadServerState(false);
    playSound("defeat");
  } catch (error) {
    console.error("Could not complete Sunny Island adventure.", error);
    showMessage(error.message, 4200);
  } finally {
    gameState.busy = false;
    updateHud();
  }
}

function itemField(category) {
  return category === "weapons" ? "weapon"
    : category === "skins" ? "skin"
      : category === "pets" ? "pet" : "trail";
}

function renderShop() {
  const grid = document.querySelector("#shop-grid");
  if (!grid) return;
  grid.replaceChildren();
  const ar = isArabic();
  const text = TEXT[ar ? "ar" : "en"];
  const owned = new Set(gameState.shop.owned);
  const items = gameState.shop.catalog.filter((item) => item.category === gameState.shopCategory);
  const shopNames = {
    "MP40 Rapid": "إم بي 40 السريع",
    "AK-47 Heavy": "آي كيه 47 الثقيل",
    "Neon Crown": "تاج النيون",
    "Sakura Bloom": "أزهار ساكورا",
    "Golden Empress": "الإمبراطورة الذهبية",
    "Snoop Classic": "سنوب كلاسيك",
    "Snoop Cyber": "سنوب سايبور",
    "Neon Pink": "نيون وردي",
    "Cyber Lightning": "برق سايبور",
    "Solar Gold": "ذهبي شمسي",
  };
  const symbols = { weapons: "✦", skins: "S", pets: "P", trails: "↗" };
  for (const item of items) {
    const card = document.createElement("article");
    card.className = "shop-card";
    const art = document.createElement("div");
    art.className = "shop-art";
    art.textContent = symbols[item.category] || "✦";
    const copy = document.createElement("div");
    copy.className = "shop-copy";
    const name = document.createElement("strong");
    name.textContent = ar ? (shopNames[item.name] || item.name) : item.name;
    const details = document.createElement("small");
    details.textContent = `${text[item.category] || item.category} · ${item.price} ${text.coins}`;
    const action = document.createElement("button");
    action.type = "button";
    const isOwned = owned.has(item.key);
    const equipped = gameState.shop.loadout[itemField(item.category)] === item.key;
    if (equipped) {
      action.textContent = text.equipped;
      action.disabled = true;
    } else if (isOwned) {
      action.textContent = text.equip;
      action.addEventListener("click", () => void equipShopItem(item));
    } else {
      action.textContent = `${text.buy} · ${item.price}`;
      action.disabled = gameState.busy;
      action.addEventListener("click", () => void buyShopItem(item));
    }
    copy.append(name, details, action);
    card.append(art, copy);
    grid.append(card);
  }
}

function applyShopLoadout() {
  gameState.unlockedWeapons = new Set(["wand"]);
  for (const key of ["weapon_mp40", "weapon_ak47"]) {
    if (gameState.shop.owned.includes(key)) gameState.unlockedWeapons.add(key.replace("weapon_", ""));
  }
  const equippedWeapon = gameState.shop.loadout.weapon?.replace("weapon_", "");
  if (equippedWeapon && gameState.unlockedWeapons.has(equippedWeapon)) {
    gameState.selectedWeapon = equippedWeapon;
  } else if (!gameState.unlockedWeapons.has(gameState.selectedWeapon)) {
    gameState.selectedWeapon = "wand";
  }
  buildHeldWeapon(gameState.selectedWeapon);
  applyPlayerSkin();
  updateWeaponButtons();
  void loadEquippedPet();
}

function updateWeaponButtons() {
  document.querySelectorAll("[data-weapon]").forEach((button) => {
    const weaponId = button.getAttribute("data-weapon");
    if (!weaponId) return;
    button.classList.toggle("is-active", weaponId === gameState.selectedWeapon);
    button.disabled = !gameState.unlockedWeapons.has(weaponId);
  });
}

function selectWeapon(weaponId) {
  if (!gameState.unlockedWeapons.has(weaponId)) {
    showMessage(isArabic() ? "اشترِ السلاح من متجر الجزيرة لفتحه." : "Unlock this weapon in the Island Shop first.", 2500);
    return;
  }
  gameState.selectedWeapon = weaponId;
  buildHeldWeapon(weaponId);
  updateWeaponButtons();
  showMessage(isArabic() ? `تم تجهيز ${weaponId}.` : `${weaponId.toUpperCase()} equipped.`, 1500);
}

async function refreshShop() {
  if (!gameState.authenticated) return;
  const result = await api("/api/games/sunny-island/shop");
  gameState.shop = result;
  const total = document.querySelector("#wallet-total");
  const balance = document.querySelector("#wallet-balance");
  const purchased = document.querySelector("#wallet-purchased");
  const earned = document.querySelector("#wallet-earned");
  if (total) total.textContent = new Intl.NumberFormat().format(result.wallet.total);
  if (balance) balance.textContent = new Intl.NumberFormat().format(result.wallet.total);
  if (purchased) purchased.textContent = new Intl.NumberFormat().format(result.wallet.purchased);
  if (earned) earned.textContent = new Intl.NumberFormat().format(result.wallet.earned);
  applyShopLoadout();
  renderShop();
}

async function buyShopItem(item) {
  if (gameState.busy) return;
  gameState.busy = true;
  const status = document.querySelector("#shop-status");
  if (status) status.textContent = isArabic() ? "جارٍ إتمام الشراء…" : "Completing purchase…";
  updateHud();
  try {
    gameState.shop = await api("/api/games/sunny-island/shop/buy", "POST", { item_key: item.key });
    await refreshShop();
    if (status) status.textContent = isArabic() ? "تم شراء العنصر بشكل دائم." : "Item purchased permanently.";
    playSound("crate");
  } catch (error) {
    if (status) status.textContent = error.message;
    console.error("Could not purchase Sunny Island shop item.", error);
  } finally {
    gameState.busy = false;
    updateHud();
    renderShop();
  }
}

async function equipShopItem(item) {
  if (gameState.busy) return;
  gameState.busy = true;
  const loadout = { ...gameState.shop.loadout, [itemField(item.category)]: item.key };
  const status = document.querySelector("#shop-status");
  try {
    const result = await api("/api/games/sunny-island/loadout", "POST", loadout);
    gameState.shop.loadout = result.loadout;
    gameState.shop.owned = result.owned;
    applyShopLoadout();
    if (status) status.textContent = isArabic() ? "تم تجهيز العنصر." : `${item.name} equipped.`;
  } catch (error) {
    if (status) status.textContent = error.message;
    console.error("Could not save Sunny Island loadout.", error);
  } finally {
    gameState.busy = false;
    updateHud();
    renderShop();
  }
}

async function loadEquippedPet() {
  const key = gameState.shop.loadout.pet;
  if (key === gameState.equippedPet) return;
  gameState.equippedPet = key || null;
  const version = ++gameState.petLoadVersion;
  if (equippedPetActor) {
    scene.remove(equippedPetActor.group);
    equippedPetActor.mixer?.stopAllAction();
    equippedPetActor = null;
  }
  const filename = key === "pet_snoop_classic" ? "deer.glb"
    : key === "pet_snoop_cyber" ? "fox.glb" : null;
  if (!filename) return;
  try {
    const gltf = await loadGLTFWithTimeout(`./assets/sunny-3d/models/${filename}`);
    if (version !== gameState.petLoadVersion) return;
    const model = gltf.scene;
    const bounds = new THREE.Box3().setFromObject(model);
    const size = bounds.getSize(new THREE.Vector3());
    const center = bounds.getCenter(new THREE.Vector3());
    const scale = 2.4 / Math.max(size.y, 0.1);
    model.scale.multiplyScalar(scale);
    model.position.set(-center.x * scale, -bounds.min.y * scale, -center.z * scale);
    const group = new THREE.Group();
    group.add(model);
    group.position.set(player.position.x + 2.8, 0.53, player.position.z + 2.8);
    scene.add(group);
    const mixer = new THREE.AnimationMixer(group);
    const walk = gltf.animations.find((clip) => /walk|run/i.test(clip.name))
      || chooseIdleClip(gltf.animations);
    if (walk) mixer.clipAction(walk).play();
    equippedPetActor = { group, mixer };
  } catch (error) {
    if (version !== gameState.petLoadVersion) return;
    console.error(`Could not load equipped island pet (${filename}).`, error);
    showMessage(isArabic() ? "تعذر تحميل الحيوان المجهز؛ يستخدم شكلاً بديلاً." : "The equipped pet could not load; a procedural companion is being used.", 3500);
    const group = createFallbackCreature([player.position.x + 2.8, player.position.z + 2.8], "pet");
    equippedPetActor = { group, mixer: null };
  }
}

async function collectItem(item) {
  if (!gameState.authenticated || !gameState.session) {
    showMessage(isArabic() ? "سجّل الدخول وابدأ مغامرة لجمع الكنوز." : "Sign in and start an adventure to collect keepsakes.", 3000);
    return;
  }
  if (gameState.busy || !collectibleMeshes.get(item.id)?.visible) return;
  gameState.busy = true;
  updateHud();
  try {
    if (gameState.serverZone !== item.zone) await syncServerZone(item.zone);
    if (gameState.serverZone !== item.zone) throw new Error("Could not enter the collectible's island zone.");
    const result = await sendServerAction("collect", item.id);
    const group = collectibleMeshes.get(item.id);
    if (group) group.visible = false;
    gameState.progress = result.progress || gameState.progress;
    updateHud();
    playSound("heal");
    showMessage(isArabic() ? "تمت إضافة الكنز إلى سجل الجزيرة." : "Keepsake added to your Island Codex!", 2600);
  } catch (error) {
    console.error(`Could not save collectible ${item.id}.`, error);
    showMessage(error.message, 3600);
  } finally {
    gameState.busy = false;
    updateHud();
  }
}

async function activateBeacon(beacon) {
  if (!gameState.authenticated || !gameState.session) {
    showMessage(isArabic() ? "ابدأ مغامرة نشطة لتفعيل المنارات." : "Start an active adventure to activate beacons.", 2800);
    return;
  }
  gameState.busy = true;
  updateHud();
  try {
    if (gameState.serverZone !== beacon.zone) await syncServerZone(beacon.zone);
    if (gameState.serverZone !== beacon.zone) throw new Error("Could not enter the beacon's island zone.");
    const result = await sendServerAction("activate_beacon", beacon.id);
    gameState.session = result.session || gameState.session;
    updateHud();
    playSound("heal");
    showMessage(isArabic() ? "أضيئت منارة الرصيف!" : "Pier beacon activated!", 2200);
  } catch (error) {
    console.error(`Could not activate beacon ${beacon.id}.`, error);
    showMessage(error.message, 3600);
  } finally {
    gameState.busy = false;
    updateHud();
  }
}

function renderCodex() {
  const grid = document.querySelector("#codex-grid");
  const summary = document.querySelector("#codex-summary");
  if (!grid) return;
  grid.replaceChildren();
  const found = new Set(gameState.progress.unlocked_collectibles);
  const ar = isArabic();
  const collectibleNames = {
    golden_compass: "البوصلة الذهبية",
    neon_relic: "أثر النيون",
    drift_token: "رمز الانجراف",
    sun_crate: "صندوق الشمس",
    palm_pack: "حقيبة النخيل",
    snoop_badge: "شارة سنوب",
    reef_sticker: "ملصق الشعاب",
    sunset_sticker: "ملصق الغروب",
    canyon_sticker: "ملصق الوادي",
  };
  const entries = gameState.codex.length
    ? gameState.codex
    : PICKUPS.map((pickup) => ({ ...pickup, name: pickup.id.replaceAll("_", " "), image: "" }));
  for (const collectible of entries) {
    const card = document.createElement("article");
    const discovered = found.has(collectible.id);
    card.className = `codex-card${discovered ? "" : " is-locked"}`;
    const art = document.createElement("div");
    art.className = "codex-art";
    if (collectible.image) {
      const image = document.createElement("img");
      image.src = collectible.image;
      image.alt = discovered ? collectible.name : TEXT[ar ? "ar" : "en"].undiscoveredAlt;
      image.loading = "lazy";
      art.append(image);
    } else {
      art.textContent = discovered ? "✦" : "?";
    }
    const copy = document.createElement("div");
    const name = document.createElement("strong");
    name.textContent = discovered
      ? (ar ? collectibleNames[collectible.id] || collectible.name : collectible.name)
      : "???";
    const status = document.createElement("small");
    status.textContent = discovered ? TEXT[isArabic() ? "ar" : "en"].found : TEXT[isArabic() ? "ar" : "en"].notFound;
    copy.append(name, status);
    card.append(art, copy);
    grid.append(card);
  }
  if (summary) {
    const text = TEXT[isArabic() ? "ar" : "en"];
    summary.textContent = `${found.size} / ${entries.length} ${text.discoveredCount}.`;
  }
}

async function refreshLeaderboard() {
  const status = document.querySelector("#leaderboard-status");
  const list = document.querySelector("#leaderboard-list");
  if (!status || !list) return;
  status.textContent = TEXT[isArabic() ? "ar" : "en"].loading;
  list.replaceChildren();
  try {
    const result = await api("/api/games/sunny-island/leaderboard");
    const rows = Array.isArray(result.leaderboard) ? result.leaderboard : [];
    status.textContent = rows.length
      ? TEXT[isArabic() ? "ar" : "en"].topTen
      : TEXT[isArabic() ? "ar" : "en"].noExplorers;
    const cosmetic = TEXT[isArabic() ? "ar" : "en"].leaderboardCosmetics;
    rows.forEach((row, index) => {
      const card = document.createElement("article");
      card.className = "leaderboard-row";
      const rank = document.createElement("strong");
      rank.className = "leaderboard-rank";
      rank.textContent = `#${index + 1}`;
      const playerInfo = document.createElement("div");
      playerInfo.className = "leaderboard-player";
      const username = document.createElement("strong");
      username.textContent = row.username;
      const details = document.createElement("small");
      details.textContent = `${TEXT[isArabic() ? "ar" : "en"].level} ${row.island_level} · ${row.xp} ${TEXT[isArabic() ? "ar" : "en"].xp} · ${row.completed_adventures} ${TEXT[isArabic() ? "ar" : "en"].completed}`;
      playerInfo.append(username, details);
      const badges = document.createElement("div");
      badges.className = "leaderboard-badges";
      for (const key of [row.equipped_skin, row.equipped_pet]) {
        if (!key) continue;
        const badge = document.createElement("span");
        badge.textContent = cosmetic[key] || key;
        badges.append(badge);
      }
      card.append(rank, playerInfo, badges);
      list.append(card);
    });
  } catch (error) {
    status.textContent = error.message;
    console.error("Could not load Sunny Island leaderboard.", error);
  }
}

function createProjectile() {
  const stats = WEAPON_STATS[gameState.selectedWeapon] || WEAPON_STATS.wand;
  const target = enemies
    .filter((enemy) => enemy.alive && enemy.zone === activeZoneId)
    .map((enemy) => ({ enemy, distance: Math.hypot(enemy.root.position.x - player.position.x, enemy.root.position.z - player.position.z) }))
    .filter((entry) => entry.distance <= stats.range)
    .sort((a, b) => a.distance - b.distance)[0]?.enemy || null;
  const direction = target
    ? new THREE.Vector3(target.root.position.x - player.position.x, target.root.position.y + 2.3 - (player.position.y + 2.5), target.root.position.z - player.position.z).normalize()
    : playerState.direction.clone().setY(0.04).normalize();
  if (target) {
    const aimDirection = new THREE.Vector3(direction.x, 0, direction.z);
    if (aimDirection.lengthSq() > 0.0001) playerState.direction.copy(aimDirection.normalize());
  }
  player.updateMatrixWorld(true);
  const origin = player.localToWorld(new THREE.Vector3(0.84, 2.48, -0.8));
  const projectileMaterial = new THREE.MeshBasicMaterial({ color: stats.color, toneMapped: false });
  const mesh = new THREE.Mesh(
    new THREE.SphereGeometry(gameState.selectedWeapon === "ak47" ? 0.25 : 0.18, 8, 6),
    projectileMaterial,
  );
  mesh.position.copy(origin);
  scene.add(mesh);
  projectiles.push({
    mesh,
    velocity: direction.multiplyScalar(stats.speed),
    life: stats.range / stats.speed,
    damage: stats.damage,
    targetId: target?.id || null,
    color: stats.color,
  });
}

function fire() {
  if (!player) return;
  const stats = WEAPON_STATS[gameState.selectedWeapon] || WEAPON_STATS.wand;
  const now = performance.now();
  if (now - gameState.lastFireAt < stats.cooldown * 1000) return;
  gameState.lastFireAt = now;
  createProjectile();
  playerActionUntil = now + 320;
  if (playerActions.shoot) playPlayerAnimation("shoot", 0.06);
  weapon.rotation.x = -0.42;
  window.setTimeout(() => { if (weapon) weapon.rotation.x = -0.2; }, 100);
  playSound("shoot");
}

function performAction() {
  const interaction = gameState.interaction;
  if (!interaction) return;
  if (interaction.type === "collectible") void collectItem(interaction.item);
  else if (interaction.type === "beacon") void activateBeacon(interaction.beacon);
  else if (interaction.type === "supply") openSupplyCrate();
  else if (interaction.type === "board") {
    if (gameState.session) showMessage(isArabic() ? "مغامرتك اليومية جارية بالفعل." : "Your daily adventure is already in progress.", 2200);
    else void startAdventure();
  }
}

function jump() {
  if (playerState.jumpOffset <= 0.02 && performance.now() > gameState.knockedDownUntil) {
    playerState.vy = 7.6;
    if (playerActions.jump) {
      playerActions.jump.reset().play();
      playerCurrentAction = playerActions.jump;
    }
  }
}

function dash() {
  if (performance.now() < gameState.knockedDownUntil) return;
  playerState.dashTime = 0.26;
  playSound("dash");
}

function wrapCameraYaw(yaw) {
  const fullTurn = Math.PI * 2;
  return ((yaw % fullTurn) + fullTurn) % fullTurn;
}

function orbitCameraBy(deltaX, deltaY) {
  cameraYaw = wrapCameraYaw(cameraYaw + deltaX * CAMERA_DRAG_SENSITIVITY);
  cameraPitch = THREE.MathUtils.clamp(
    cameraPitch + deltaY * CAMERA_PITCH_DRAG_SENSITIVITY,
    CAMERA_PITCH_LIMITS.min,
    CAMERA_PITCH_LIMITS.max,
  );
}

function isCameraOrbitExcludedPoint(x, y) {
  const controls = document.querySelectorAll(
    ".hud-top, .player-status, .quest-hud, .weapon-picker, .interaction-prompt, .scene-message, .controls-hint, .joystick, .touch-buttons, button, a, input, select, textarea",
  );
  return [...controls].some((element) => {
    const bounds = element.getBoundingClientRect();
    return bounds.width > 0 && bounds.height > 0
      && x >= bounds.left && x <= bounds.right
      && y >= bounds.top && y <= bounds.bottom;
  });
}

function bindControls() {
  window.addEventListener("keydown", (event) => {
    const key = event.key.toLowerCase();
    if ([" ", "arrowup", "arrowdown", "arrowleft", "arrowright", "1", "2", "3"].includes(key)) event.preventDefault();
    keyState.add(key);
    if (event.repeat) return;
    if (key === " ") jump();
    if (key === "shift") dash();
    if (key === "f") fire();
    if (key === "e") {
      cameraTurnKeyStartedAt = performance.now();
      if (gameState.interaction) void performAction();
    }
    if (key === "r") {
      cameraYaw = 0;
      cameraPitch = DEFAULT_CAMERA_PITCH;
    }
    if (key === "1") selectWeapon("wand");
    if (key === "2") selectWeapon("mp40");
    if (key === "3") selectWeapon("ak47");
  });
  window.addEventListener("keyup", (event) => {
    const key = event.key.toLowerCase();
    keyState.delete(key);
    if (key === "e") cameraTurnKeyStartedAt = 0;
  });
  window.addEventListener("blur", () => {
    keyState.clear();
    cameraPointer = null;
    cameraTurnKeyStartedAt = 0;
  });
  canvas.addEventListener("pointerdown", (event) => {
    const isTouch = event.pointerType !== "mouse";
    const canvasBounds = canvas.getBoundingClientRect();
    const startsOnRightHalf = event.clientX >= canvasBounds.left + canvasBounds.width / 2;
    const isOrbitButton = event.pointerType === "mouse" && (event.button === 1 || event.button === 2);
    const canOrbitOnDrag = event.pointerType === "mouse"
      ? event.button === 0
      : startsOnRightHalf;
    if ((!isOrbitButton && !canOrbitOnDrag)
      || (isTouch && !startsOnRightHalf)
      || isCameraOrbitExcludedPoint(event.clientX, event.clientY)) return;

    if (isTouch || isOrbitButton) event.preventDefault();
    cameraPointer = {
      pointerId: event.pointerId,
      startX: event.clientX,
      startY: event.clientY,
      lastX: event.clientX,
      lastY: event.clientY,
      canOrbitOnDrag,
      orbiting: isOrbitButton,
      dragged: isOrbitButton,
      shouldFire: event.pointerType === "mouse" && event.button === 0,
    };
    canvas.setPointerCapture(event.pointerId);
  });
  canvas.addEventListener("pointermove", (event) => {
    if (!cameraPointer || event.pointerId !== cameraPointer.pointerId) return;
    const deltaX = event.clientX - cameraPointer.lastX;
    const deltaY = event.clientY - cameraPointer.lastY;
    const totalDistance = Math.hypot(
      event.clientX - cameraPointer.startX,
      event.clientY - cameraPointer.startY,
    );
    if (!cameraPointer.orbiting && cameraPointer.canOrbitOnDrag && totalDistance > 6) {
      cameraPointer.orbiting = true;
      cameraPointer.dragged = true;
    }
    if (cameraPointer.orbiting) {
      orbitCameraBy(deltaX, deltaY);
      event.preventDefault();
    }
    cameraPointer.lastX = event.clientX;
    cameraPointer.lastY = event.clientY;
  });
  const finishCameraPointer = (event, allowFire) => {
    if (!cameraPointer || event.pointerId !== cameraPointer.pointerId) return;
    const totalDistance = Math.hypot(
      event.clientX - cameraPointer.startX,
      event.clientY - cameraPointer.startY,
    );
    const shouldFire = allowFire && cameraPointer.shouldFire && !cameraPointer.dragged && totalDistance <= 6;
    cameraPointer = null;
    if (shouldFire) fire();
  };
  canvas.addEventListener("pointerup", (event) => finishCameraPointer(event, true));
  canvas.addEventListener("pointercancel", (event) => finishCameraPointer(event, false));
  canvas.addEventListener("contextmenu", (event) => event.preventDefault());

  document.querySelectorAll("[data-action]").forEach((button) => {
    const action = button.getAttribute("data-action");
    const run = () => {
      if (action === "jump") jump();
      if (action === "dash") dash();
      if (action === "interact") void performAction();
      if (action === "fire") fire();
    };
    button.addEventListener("pointerdown", (event) => {
      event.preventDefault();
      run();
    });
    button.addEventListener("click", (event) => {
      if (event.detail === 0) run();
    });
  });

  if (joystick && joystickKnob) {
    let pointerId = null;
    const resetJoystick = () => {
      pointerId = null;
      touchInput.x = 0;
      touchInput.z = 0;
      joystickKnob.style.transform = "translate(-50%, -50%)";
    };
    joystick.addEventListener("pointerdown", (event) => {
      event.preventDefault();
      pointerId = event.pointerId;
      joystick.setPointerCapture(pointerId);
      updateJoystick(event);
    });
    joystick.addEventListener("pointermove", (event) => {
      if (event.pointerId === pointerId) updateJoystick(event);
    });
    joystick.addEventListener("pointerup", resetJoystick);
    joystick.addEventListener("pointercancel", resetJoystick);
    function updateJoystick(event) {
      const bounds = joystick.getBoundingClientRect();
      const radius = bounds.width * 0.38;
      const dx = event.clientX - (bounds.left + bounds.width / 2);
      const dy = event.clientY - (bounds.top + bounds.height / 2);
      const distance = Math.min(radius, Math.hypot(dx, dy));
      const angle = Math.atan2(dy, dx);
      const x = Math.cos(angle) * distance;
      const y = Math.sin(angle) * distance;
      const deadzone = radius * 0.12;
      const strength = distance <= deadzone
        ? 0
        : Math.pow((distance - deadzone) / (radius - deadzone), 1.35);
      touchInput.x = Math.cos(angle) * strength;
      touchInput.z = Math.sin(angle) * strength;
      joystickKnob.style.transform = `translate(calc(-50% + ${x}px), calc(-50% + ${y}px))`;
    }
  }

  document.querySelector("#adventure-start").addEventListener("click", () => void startAdventure());
  document.querySelector("#adventure-complete").addEventListener("click", () => void completeAdventure());
  document.querySelectorAll("[data-weapon]").forEach((button) => {
    button.addEventListener("click", () => selectWeapon(button.getAttribute("data-weapon")));
  });
  document.querySelectorAll("[data-shop-tab]").forEach((button) => {
    button.addEventListener("click", () => {
      gameState.shopCategory = button.getAttribute("data-shop-tab") || "weapons";
      document.querySelectorAll("[data-shop-tab]").forEach((tab) => tab.classList.toggle("is-active", tab === button));
      renderShop();
    });
  });
  document.querySelector("#open-shop").addEventListener("click", () => {
    if (!gameState.authenticated) {
      showMessage(isArabic() ? "سجّل الدخول لفتح متجر الجزيرة." : "Sign in to open the Island Shop.", 2800);
      return;
    }
    const dialog = document.querySelector("#shop-dialog");
    if (!dialog.open) dialog.showModal();
    void refreshShop().catch((error) => {
      document.querySelector("#shop-status").textContent = error.message;
      console.error("Could not refresh the Island Shop.", error);
    });
  });
  document.querySelector("#open-codex").addEventListener("click", () => {
    renderCodex();
    document.querySelector("#codex-dialog").showModal();
  });
  document.querySelector("#open-leaderboard").addEventListener("click", () => {
    document.querySelector("#leaderboard-dialog").showModal();
    void refreshLeaderboard();
  });
  document.querySelectorAll("[data-close]").forEach((button) => {
    button.addEventListener("click", () => document.querySelector(`#${button.getAttribute("data-close")}`).close());
  });
  document.querySelectorAll(".game-dialog").forEach((dialog) => {
    dialog.addEventListener("click", (event) => {
      if (event.target === dialog) dialog.close();
    });
  });
  document.querySelector("#mute-toggle").addEventListener("click", (event) => {
    gameState.muted = !gameState.muted;
    const button = event.currentTarget;
    button.setAttribute("aria-pressed", String(gameState.muted));
    localizeStaticLabels();
    if (!gameState.muted) playSound("crate");
  });
  document.querySelector("#language-toggle").addEventListener("click", () => {
    const language = isArabic() ? "en" : "ar";
    languageOverride = language;
    try {
      window.localStorage.setItem("neen-language", language);
    } catch (error) {
      console.warn("Could not save the Sunny Island language preference.", error);
    }
    window.dispatchEvent(new CustomEvent("neen:languagechange", { detail: { language } }));
  });
  window.addEventListener("neen:languagechange", (event) => {
    if (["en", "ar"].includes(event.detail?.language)) {
      languageOverride = event.detail.language;
    }
    localizeStaticLabels();
  });
  window.addEventListener("storage", (event) => {
    if (event.key === "neen-language") {
      languageOverride = null;
      localizeStaticLabels();
    }
  });
}

function playSound(type) {
  if (gameState.muted) return;
  const AudioContextType = window.AudioContext || window.webkitAudioContext;
  if (!AudioContextType) {
    console.warn("Web Audio is unavailable; Sunny Island sound effects are disabled.");
    return;
  }
  try {
    gameState.audioContext ||= new AudioContextType();
  } catch (error) {
    console.error("Could not initialize Sunny Island audio.", error);
    return;
  }
  const context = gameState.audioContext;
  if (context.state === "suspended") {
    context.resume().catch((error) => console.error("Could not resume Sunny Island audio.", error));
  }
  const profiles = {
    shoot: [520, 180, 0.09, "triangle"],
    hit: [260, 520, 0.13, "sine"],
    hurt: [220, 90, 0.16, "triangle"],
    heal: [360, 720, 0.28, "sine"],
    dash: [650, 230, 0.12, "triangle"],
    crate: [300, 600, 0.22, "triangle"],
    defeat: [440, 880, 0.42, "triangle"],
    boss: [180, 880, 0.48, "sawtooth"],
  };
  const [startFrequency, endFrequency, duration, waveform] = profiles[type] || profiles.hit;
  const oscillator = context.createOscillator();
  const gain = context.createGain();
  const now = context.currentTime;
  oscillator.type = waveform;
  oscillator.frequency.setValueAtTime(startFrequency, now);
  oscillator.frequency.exponentialRampToValueAtTime(endFrequency, now + duration);
  gain.gain.setValueAtTime(type === "boss" ? 0.12 : 0.075, now);
  gain.gain.exponentialRampToValueAtTime(0.001, now + duration);
  oscillator.connect(gain);
  gain.connect(context.destination);
  oscillator.start(now);
  oscillator.stop(now + duration + 0.02);
}

function spawnCombatDrop(type, position) {
  const group = new THREE.Group();
  if (type === "medkit") {
    box(group, [1.35, 1.05, 0.85], [0, 0.75, 0], mats.white);
    box(group, [0.28, 0.86, 0.9], [0, 0.75, -0.02], mats.red);
    box(group, [0.86, 0.28, 0.92], [0, 0.75, -0.02], mats.red);
  } else {
    addMesh(group, new THREE.OctahedronGeometry(0.85, 0), material(0x71daf1, 0.34, { emissive: 0x2989ba, emissiveIntensity: 0.34 }), [0, 0.8, 0]);
    addMesh(group, new THREE.TorusGeometry(1.05, 0.06, 6, 16), mats.white, [0, 0.2, 0]).rotation.x = Math.PI / 2;
  }
  group.position.set(position.x, 0.58, position.z);
  group.userData.type = type;
  group.userData.phase = Math.random() * 6;
  scene.add(group);
  combatDrops.push({ type, group, x: position.x, z: position.z, life: 24 });
}

function openSupplyCrate() {
  if (!supplyCrate || performance.now() < supplyCrate.openedUntil) return;
  const now = performance.now();
  supplyCrate.openedUntil = now + 32000;
  supplyCrate.root.visible = false;
  const type = Math.random() < 0.5 ? "medkit" : "shield";
  spawnCombatDrop(type, { x: supplyCrate.position.x + 1.5, z: supplyCrate.position.z + 1 });
  playSound("crate");
  showMessage(isArabic() ? "تم فتح صندوق الإمدادات!" : `Supply crate opened — ${type} spawned.`, 2400);
}

function damagePlayer(amount) {
  const now = performance.now();
  if (now < gameState.invulnerableUntil || now < gameState.knockedDownUntil) return;
  gameState.invulnerableUntil = now + 760;
  gameState.lastDamageAt = now;
  const absorbed = Math.min(gameState.shield, amount);
  gameState.shield -= absorbed;
  gameState.hp = Math.max(0, gameState.hp - (amount - absorbed));
  playSound("hurt");
  updateHud();
  if (gameState.hp <= 0) {
    gameState.knockedDownUntil = now + 1800;
    playerState.jumpOffset = 0;
    playerState.vy = 0;
    keyState.clear();
    showMessage(isArabic() ? "سقطت! ستعود إلى المنطقة الآمنة قريباً…" : "Knocked down! Returning to the safe zone…", 1800);
    knockdownMessageUntil = gameState.knockedDownUntil;
  }
}

function healPlayer(hp, shield) {
  const oldHp = gameState.hp;
  const oldShield = gameState.shield;
  gameState.hp = Math.min(gameState.maxHp, gameState.hp + hp);
  gameState.shield = Math.min(gameState.maxShield, gameState.shield + shield);
  if (oldHp !== gameState.hp || oldShield !== gameState.shield) playSound("heal");
  updateHud();
}

function updateEnemyHealth(enemy) {
  if (!enemy.healthFill) return;
  const ratio = THREE.MathUtils.clamp(enemy.hp / enemy.maxHp, 0, 1);
  enemy.healthFill.scale.x = Math.max(0.001, ratio);
  enemy.healthFill.position.x = -(enemy.healthBarWidth * (1 - ratio)) / 2;
  enemy.healthFill.material.color.setHex(ratio < 0.3 ? 0xff7677 : enemy.boss ? 0xffcb58 : 0x72e0ba);
}

function defeatEnemy(enemy) {
  if (!enemy.alive) return;
  enemy.alive = false;
  enemy.root.visible = false;
  spawnCombatDrop(Math.random() < 0.5 ? "medkit" : "shield", {
    x: enemy.root.position.x,
    z: enemy.root.position.z,
  });
  playSound(enemy.boss ? "boss" : "defeat");
  showMessage(isArabic() ? "تمت هزيمة العدو!" : `${enemy.name} defeated!`, 2300);
}

function damageEnemy(enemy, amount) {
  if (!enemy?.alive) return;
  enemy.hp = Math.max(0, enemy.hp - amount);
  updateEnemyHealth(enemy);
  playSound("hit");
  if (enemy.hp === 0) defeatEnemy(enemy);
}

function spawnEnemyProjectile(position, direction, damage = 12, color = 0xff527e, life = 3) {
  const mesh = new THREE.Mesh(
    new THREE.SphereGeometry(0.32, 8, 6),
    new THREE.MeshBasicMaterial({ color, toneMapped: false }),
  );
  mesh.position.copy(position);
  scene.add(mesh);
  enemyProjectiles.push({ mesh, velocity: direction.clone().normalize().multiplyScalar(15), damage, life });
}

function createTelegraph(enemy, pattern, delay, radius = 3) {
  const ring = new THREE.Mesh(
    new THREE.TorusGeometry(1, 0.09, 6, 32),
    new THREE.MeshBasicMaterial({ color: 0xff577e, transparent: true, opacity: 0.82 }),
  );
  ring.rotation.x = Math.PI / 2;
  ring.scale.set(radius, radius, 1);
  ring.position.set(enemy.root.position.x, 0.64, enemy.root.position.z);
  scene.add(ring);
  telegraphs.push({ enemy, pattern, ring, remaining: delay, radius });
}

function resolveTelegraph(telegraph) {
  const enemy = telegraph.enemy;
  if (!enemy.alive) return;
  if (telegraph.pattern === "slam") {
    const distance = Math.hypot(playerState.x - enemy.root.position.x, playerState.z - enemy.root.position.z);
    if (distance <= telegraph.radius && playerState.jumpOffset < 1.3 && playerState.dashTime <= 0) damagePlayer(22);
    return;
  }
  const count = telegraph.pattern === "radial" ? 10 : 1;
  for (let i = 0; i < count; i += 1) {
    let direction;
    if (count === 1) {
      direction = new THREE.Vector3(playerState.x - enemy.root.position.x, 0.55, playerState.z - enemy.root.position.z);
    } else {
      const angle = (Math.PI * 2 * i) / count;
      direction = new THREE.Vector3(Math.cos(angle), 0.12, Math.sin(angle));
    }
    spawnEnemyProjectile(
      new THREE.Vector3(enemy.root.position.x, enemy.root.position.y + 2.2, enemy.root.position.z),
      direction,
      enemy.boss ? 17 : 12,
      enemy.boss ? 0xffae4d : enemy.accent,
      3.2,
    );
  }
}

function readMovement() {
  const horizontal = Number(keyState.has("d") || keyState.has("arrowright"))
    - Number(keyState.has("a") || keyState.has("arrowleft")) + touchInput.x;
  const vertical = Number(keyState.has("w") || keyState.has("arrowup"))
    - Number(keyState.has("s") || keyState.has("arrowdown")) - touchInput.z;
  const forwardX = -Math.sin(cameraYaw);
  const forwardZ = -Math.cos(cameraYaw);
  const rightX = Math.cos(cameraYaw);
  const rightZ = -Math.sin(cameraYaw);
  const direction = new THREE.Vector3(
    rightX * horizontal + forwardX * vertical,
    0,
    rightZ * horizontal + forwardZ * vertical,
  );
  if (direction.lengthSq() > 1) direction.normalize();
  return direction;
}

function getPlayerColliders() {
  const colliders = worldColliders.filter((collider) => (
    !collider.jumpable || playerState.jumpOffset < collider.jumpClearance
  ));
  for (const enemy of enemies) {
    if (!enemy.alive) continue;
    colliders.push({
      x: enemy.root.position.x,
      z: enemy.root.position.z,
      radius: enemy.boss ? 3 : enemy.id.startsWith("guardian_") ? 2.15 : 1.2,
    });
  }
  return colliders;
}

function movePlayerWithCollisions(dx, dz) {
  const distance = Math.hypot(dx, dz);
  const steps = Math.max(1, Math.ceil(distance / 0.35));
  const stepX = dx / steps;
  const stepZ = dz / steps;
  const colliders = getPlayerColliders();

  for (let step = 0; step < steps; step += 1) {
    let nextX = playerState.x + stepX;
    let nextZ = playerState.z + stepZ;
    for (let pass = 0; pass < 2; pass += 1) {
      for (const collider of colliders) {
        const minDistance = PLAYER_COLLISION_RADIUS + collider.radius;
        let offsetX = nextX - collider.x;
        let offsetZ = nextZ - collider.z;
        let distanceSquared = offsetX * offsetX + offsetZ * offsetZ;
        if (distanceSquared >= minDistance * minDistance) continue;
        if (distanceSquared < 0.0001) {
          offsetX = -stepX;
          offsetZ = -stepZ;
          distanceSquared = offsetX * offsetX + offsetZ * offsetZ;
          if (distanceSquared < 0.0001) {
            offsetX = playerState.x - collider.x;
            offsetZ = playerState.z - collider.z;
            distanceSquared = offsetX * offsetX + offsetZ * offsetZ;
          }
          if (distanceSquared < 0.0001) {
            offsetX = 1;
            offsetZ = 0;
            distanceSquared = 1;
          }
        }
        const distanceToCollider = Math.sqrt(distanceSquared);
        const pushOut = minDistance - distanceToCollider + 0.001;
        nextX += (offsetX / distanceToCollider) * pushOut;
        nextZ += (offsetZ / distanceToCollider) * pushOut;
      }
    }
    playerState.x = THREE.MathUtils.clamp(nextX, 7, WORLD.width - 7);
    playerState.z = THREE.MathUtils.clamp(nextZ, 7, WORLD.depth - 7);
  }
}

function zoneAtPosition() {
  if (playerState.x < 80) return playerState.z < 50 ? "palm_grove" : "neon_pier";
  return playerState.z < 50 ? "sunset_ridge" : "loot_canyon";
}

function updateAdventureButtonState() {
  const completeButton = document.querySelector("#adventure-complete");
  if (!completeButton || !gameState.session) return;
  const complete = Array.isArray(gameState.session.objectives)
    && gameState.session.objectives.length > 0
    && gameState.session.objectives.every((objective) => objective.completed);
  completeButton.disabled = gameState.busy || !complete
    || Date.now() - (gameState.sessionStartedAt || Date.now()) < 10000;
}

function updatePlayer(delta) {
  const now = performance.now();
  const eOrbitReady = keyState.has("e") && now - cameraTurnKeyStartedAt >= CAMERA_KEY_HOLD_DELAY;
  const cameraTurn = Number(eOrbitReady) - Number(keyState.has("q"));
  cameraYaw = wrapCameraYaw(cameraYaw + cameraTurn * CAMERA_KEY_TURN_SPEED * delta);
  if (gameState.knockedDownUntil && now >= gameState.knockedDownUntil) {
    gameState.knockedDownUntil = 0;
    gameState.hp = gameState.maxHp;
    gameState.shield = gameState.maxShield;
    gameState.invulnerableUntil = now + 2400;
    playerState.x = ZONE_CENTERS.neon_pier.x;
    playerState.z = ZONE_CENTERS.neon_pier.z;
    playerState.jumpOffset = 0;
    playerState.vy = 0;
    activeZoneId = "neon_pier";
    activeZone = localizedZoneName("neon_pier");
    zoneLabel.textContent = activeZone;
    syncServerZone(activeZoneId);
    showMessage(isArabic() ? "عدت إلى المنطقة الآمنة بصحة كاملة." : "Respawned at the safe zone with full health.", 2600);
    updateHud();
  }

  const move = readMovement();
  const knockedDown = now < gameState.knockedDownUntil;
  if (!knockedDown && move.lengthSq() > 0.000001) {
    playerState.direction.copy(move).normalize();
    const speed = playerState.dashTime > 0 ? 31 : 11;
    movePlayerWithCollisions(move.x * speed * delta, move.z * speed * delta);
  }
  playerState.dashTime = knockedDown ? 0 : Math.max(0, playerState.dashTime - delta);
  playerState.x = THREE.MathUtils.clamp(playerState.x, 7, WORLD.width - 7);
  playerState.z = THREE.MathUtils.clamp(playerState.z, 7, WORLD.depth - 7);

  if (!knockedDown) playerState.vy -= 19 * delta;
  playerState.jumpOffset = Math.max(0, playerState.jumpOffset + playerState.vy * delta);
  if (playerState.jumpOffset === 0) {
    playerState.vy = 0;
    if (playerCurrentAction === playerActions.jump) playerCurrentAction = null;
  }

  player.position.set(playerState.x, GROUND_Y + playerState.jumpOffset, playerState.z);
  const targetRotation = Math.atan2(-playerState.direction.x, -playerState.direction.z);
  const rotationDelta = Math.atan2(
    Math.sin(targetRotation - player.rotation.y),
    Math.cos(targetRotation - player.rotation.y),
  );
  player.rotation.y += rotationDelta * (1 - Math.exp(-14 * delta));
  aura.rotation.z += delta * 0.55;
  weapon.rotation.z = Math.sin(now * 0.002) * 0.045;
  player.visible = now >= gameState.invulnerableUntil || Math.floor(now / 90) % 2 === 0;

  const moving = !knockedDown && move.lengthSq() > 0.000001;
  if (playerMixer) {
    if (playerState.jumpOffset > 0.05 && playerActions.jump) {
      playPlayerAnimation("jump", 0.08);
    } else if (now < playerActionUntil && playerActions.shoot) {
      playPlayerAnimation("shoot", 0.05);
    } else {
      playPlayerAnimation(moving ? "run" : "idle");
    }
    playerMixer.update(delta);
  } else if (avatarFallback) {
    const stride = moving ? Math.sin(now * 0.014) * 0.45 : 0;
    avatarFallback.userData.legs?.forEach((leg, index) => {
      leg.rotation.x = index === 0 ? stride : -stride;
    });
    avatarFallback.userData.arms?.forEach((arm, index) => {
      arm.rotation.x = moving ? (index === 0 ? -stride : stride) : 0;
    });
  }

  if (playerState.dashTime > 0 && gameState.shop.loadout.trail && now - gameState.lastTrailAt > 55) {
    gameState.lastTrailAt = now;
    const trailColor = TRAIL_COLORS[gameState.shop.loadout.trail];
    if (trailColor) {
      const trail = new THREE.Mesh(
        new THREE.SphereGeometry(0.55, 7, 5),
        new THREE.MeshBasicMaterial({ color: trailColor, transparent: true, opacity: 0.42 }),
      );
      trail.position.set(player.position.x, player.position.y + 0.4, player.position.z);
      scene.add(trail);
      dashTrailMeshes.push({ mesh: trail, life: 0.42 });
      while (dashTrailMeshes.length > 16) {
        const oldest = dashTrailMeshes.shift();
        scene.remove(oldest.mesh);
        oldest.mesh.geometry.dispose();
        oldest.mesh.material.dispose();
      }
    }
  }
  for (let i = dashTrailMeshes.length - 1; i >= 0; i -= 1) {
    const trail = dashTrailMeshes[i];
    trail.life -= delta;
    trail.mesh.material.opacity = Math.max(0, trail.life / 0.42) * 0.42;
    trail.mesh.scale.multiplyScalar(1 + delta * 0.8);
    if (trail.life <= 0) {
      scene.remove(trail.mesh);
      trail.mesh.geometry.dispose();
      trail.mesh.material.dispose();
      dashTrailMeshes.splice(i, 1);
    }
  }

  const currentZoneId = zoneAtPosition();
  if (currentZoneId !== activeZoneId) {
    activeZoneId = currentZoneId;
    activeZone = localizedZoneName(currentZoneId);
    zoneLabel.textContent = activeZone;
    showMessage(`${isArabic() ? "مرحباً بك في" : "Welcome to"} ${activeZone}.`, 2100);
    syncServerZone(currentZoneId);
    updateHud();
  }

  const horizontalDistance = CAMERA_DISTANCE * Math.cos(cameraPitch);
  const cameraTarget = player.position.clone().add(new THREE.Vector3(
    Math.sin(cameraYaw) * horizontalDistance,
    Math.sin(cameraPitch) * CAMERA_DISTANCE,
    Math.cos(cameraYaw) * horizontalDistance,
  ));
  const smoothing = 1 - Math.exp(-5.4 * delta);
  camera.position.lerp(cameraTarget, smoothing);
  camera.lookAt(player.position.x, player.position.y + 1.7, player.position.z);

  const x = Math.round(playerState.x * WORLD.scale).toString().padStart(4, "0");
  const z = Math.round(playerState.z * WORLD.scale).toString().padStart(4, "0");
  coordinates.textContent = `X ${x} · Y ${z}`;
  updateAdventureButtonState();
}

function updateProjectiles(delta) {
  for (let i = projectiles.length - 1; i >= 0; i -= 1) {
    const projectile = projectiles[i];
    projectile.life -= delta;
    projectile.mesh.position.addScaledVector(projectile.velocity, delta);
    const target = enemies.find((enemy) => enemy.id === projectile.targetId && enemy.alive)
      || enemies
        .filter((enemy) => enemy.alive && enemy.zone === activeZoneId)
        .find((enemy) => Math.hypot(
          projectile.mesh.position.x - enemy.root.position.x,
          projectile.mesh.position.y - enemy.root.position.y - (enemy.boss ? 3 : 2),
          projectile.mesh.position.z - enemy.root.position.z,
        ) < (enemy.boss ? 3.2 : 2.3));
    if (target && Math.hypot(
      projectile.mesh.position.x - target.root.position.x,
      projectile.mesh.position.y - target.root.position.y - (target.boss ? 3 : 2),
      projectile.mesh.position.z - target.root.position.z,
    ) < (target.boss ? 3.2 : 2.3)) {
      damageEnemy(target, projectile.damage);
      projectile.life = 0;
    }
    if (projectile.life <= 0) {
      scene.remove(projectile.mesh);
      projectile.mesh.geometry.dispose();
      projectile.mesh.material.dispose();
      projectiles.splice(i, 1);
    }
  }
}

function updateActors(delta, time) {
  for (let i = 0; i < animated.length; i += 1) {
    const actor = animated[i];
    actor.mixer?.update(delta);
    if (actor.kind === "pet") {
      const phase = time * 0.42 + i * 2.4;
      const nearby = Math.hypot(playerState.x - actor.position.x, playerState.z - actor.position.z) < 24;
      const shouldWalk = nearby && Math.floor(time / 4.5 + i) % 2 === 0;
      const nextAction = shouldWalk ? actor.walkAction : actor.idleAction;
      if (nextAction && nextAction !== actor.activeAction) {
        actor.activeAction?.fadeOut(0.25);
        nextAction.reset().fadeIn(0.25).play();
        actor.activeAction = nextAction;
      }
      const wander = shouldWalk ? 1 : 0;
      actor.actor.position.x = actor.position.x + Math.sin(phase) * 1.5 * wander;
      actor.actor.position.z = actor.position.z + Math.cos(phase) * 1.1 * wander;
    }
    if (actor.kind === "flying" && actor.mixer === null) {
      actor.actor.position.y = 2.1 + Math.sin(time * 2 + i) * 0.35;
    }
  }
  if (equippedPetActor) {
    const side = new THREE.Vector3(-playerState.direction.z, 0, playerState.direction.x);
    const desired = new THREE.Vector3(
      player.position.x - playerState.direction.x * 3 + side.x * 1.7,
      0.53,
      player.position.z - playerState.direction.z * 3 + side.z * 1.7,
    );
    equippedPetActor.group.position.lerp(desired, 1 - Math.exp(-4.4 * delta));
    equippedPetActor.group.rotation.y = Math.atan2(playerState.direction.x, -playerState.direction.z);
    equippedPetActor.mixer?.update(delta);
  }
}

function updateCombat(delta, now) {
  const activeEnemies = enemies.filter((enemy) => enemy.alive && enemy.zone === activeZoneId);
  for (const enemy of enemies) {
    if (!enemy.alive) continue;
    if (enemy.kind === "small" || enemy.kind === "flying") {
      enemy.phase += delta * 1.3;
      enemy.root.position.x = enemy.x + Math.sin(enemy.phase) * 3.2;
      enemy.root.position.z = enemy.z + Math.cos(enemy.phase * 0.8) * 2.6;
      enemy.root.position.y = (enemy.kind === "flying" ? 2.35 : 0.53) + Math.sin(enemy.phase * 1.7) * 0.42;
    }
    if (!activeEnemies.includes(enemy) || now < gameState.knockedDownUntil) continue;
    enemy.mixer?.update(delta);
    const distance = Math.hypot(playerState.x - enemy.root.position.x, playerState.z - enemy.root.position.z);
    if (distance > 35 || now < enemy.nextAttackAt) continue;

    if (enemy.boss) {
      if (now >= enemy.nextSlamAt) {
        createTelegraph(enemy, "slam", 1.05, 12);
        enemy.nextSlamAt = now + 8200;
      } else {
        createTelegraph(enemy, "radial", 0.78, 4.2);
      }
      enemy.nextAttackAt = now + (enemy.hp < enemy.maxHp / 2 ? 3900 : 5200);
    } else {
      createTelegraph(enemy, "shot", enemy.kind ? 0.42 : 0.7, 3.5);
      enemy.nextAttackAt = now + (enemy.kind ? 2450 : 3400);
    }
  }

  for (let i = telegraphs.length - 1; i >= 0; i -= 1) {
    const telegraph = telegraphs[i];
    telegraph.remaining -= delta;
    telegraph.ring.material.opacity = Math.max(0.15, Math.min(1, telegraph.remaining / 0.9));
    if (telegraph.remaining <= 0) {
      resolveTelegraph(telegraph);
      scene.remove(telegraph.ring);
      telegraph.ring.geometry.dispose();
      telegraph.ring.material.dispose();
      telegraphs.splice(i, 1);
    }
  }

  for (let i = enemyProjectiles.length - 1; i >= 0; i -= 1) {
    const projectile = enemyProjectiles[i];
    projectile.life -= delta;
    projectile.mesh.position.addScaledVector(projectile.velocity, delta);
    if (projectile.life <= 0) {
      scene.remove(projectile.mesh);
      projectile.mesh.geometry.dispose();
      projectile.mesh.material.dispose();
      enemyProjectiles.splice(i, 1);
      continue;
    }
    const distance = projectile.mesh.position.distanceTo(new THREE.Vector3(playerState.x, player.position.y + 1.8, playerState.z));
    if (distance < 1.35) {
      if (playerState.jumpOffset < 1.1 && playerState.dashTime <= 0) damagePlayer(projectile.damage);
      scene.remove(projectile.mesh);
      projectile.mesh.geometry.dispose();
      projectile.mesh.material.dispose();
      enemyProjectiles.splice(i, 1);
    }
  }

  for (let i = combatDrops.length - 1; i >= 0; i -= 1) {
    const drop = combatDrops[i];
    drop.life -= delta;
    drop.group.position.y = 0.58 + Math.sin(performance.now() / 360 + drop.group.userData.phase) * 0.16;
    let distance = Math.hypot(playerState.x - drop.group.position.x, playerState.z - drop.group.position.z);
    if (gameState.equippedPet && distance < 13) {
      drop.group.position.x += (playerState.x - drop.group.position.x) * Math.min(1, delta * 2.2);
      drop.group.position.z += (playerState.z - drop.group.position.z) * Math.min(1, delta * 2.2);
      distance = Math.hypot(playerState.x - drop.group.position.x, playerState.z - drop.group.position.z);
    }
    if (distance < 1.9) {
      if (drop.type === "medkit") healPlayer(32, 0);
      else healPlayer(0, 27);
      showMessage(isArabic() ? (drop.type === "medkit" ? "تم جمع حقيبة الإسعاف." : "تم جمع الدرع.") : `${drop.type === "medkit" ? "Medkit" : "Shield"} collected.`, 1800);
      drop.life = 0;
    }
    if (drop.life <= 0) {
      scene.remove(drop.group);
      drop.group.traverse((node) => {
        if (node.isMesh) node.geometry.dispose();
      });
      combatDrops.splice(i, 1);
    }
  }
  if (supplyCrate) supplyCrate.root.visible = performance.now() >= supplyCrate.openedUntil;
}

function render(time) {
  if (document.hidden || !renderer) {
    frameHandle = 0;
    return;
  }
  const delta = Math.min(0.05, lastFrame ? (time - lastFrame) / 1000 : 0.016);
  lastFrame = time;
  updatePlayer(delta);
  updateActors(delta, time / 1000);
  updateCombat(delta, time);
  updateProjectiles(delta);
  updateWorldInteractions(delta, time / 1000);
  if (isArabic() !== (document.documentElement.lang === "ar")) localizeStaticLabels();
  if (messageTimer > 0) {
    messageTimer -= delta * 1000;
    if (messageTimer <= 0) message.textContent = "";
  }
  renderer.render(scene, camera);
  frameHandle = window.requestAnimationFrame(render);
}

async function loadIslandAssets(enemyLoads) {
  const assetPlan = [
    {
      path: "./assets/sunny-3d/models/palm-trees.glb",
      positions: NATURE_LAYOUT.palms,
      height: 7.2,
    },
    {
      path: "./assets/sunny-3d/models/bushes.glb",
      positions: NATURE_LAYOUT.bushes,
      height: 2.2,
    },
  ];
  const staticLoads = assetPlan.map(async (asset) => {
    try {
      const gltf = await loadGLTFWithTimeout(asset.path);
      placeAssetNature(gltf, asset.positions, asset.height);
      return true;
    } catch (error) {
      console.error(`Could not load local nature asset (${asset.path}).`, error);
      return false;
    }
  });
  const actorLoads = [
    loadPlayerAvatar(),
    loadAnimatedModel("./assets/sunny-3d/models/deer.glb", [50, 81], 3.1, "pet"),
    loadAnimatedModel("./assets/sunny-3d/models/fox.glb", [66, 40], 2.5, "pet"),
  ];
  const [natureResults] = await Promise.all([
    Promise.all(staticLoads),
    Promise.all(actorLoads),
    Promise.all(enemyLoads),
  ]);
  if (natureResults.some((loaded) => !loaded)) {
    if (!natureResults[0]) NATURE_LAYOUT.palms.forEach((position, index) => createProceduralTree(position, index));
    if (!natureResults[1]) NATURE_LAYOUT.bushes.forEach((position, index) => createProceduralBush(position, index));
    showMessage("Some nature models were unavailable; the island is using built-in 3D props.", 4000);
  }
  if (message.textContent === "Loading the island…") message.textContent = "";
}

function init() {
  if (!setupRenderer()) return;

  scene = new THREE.Scene();
  scene.background = new THREE.Color(0xb9eff4);
  scene.fog = new THREE.Fog(0xb9eff4, 115, 215);
  camera = new THREE.PerspectiveCamera(54, 1, 0.1, 320);

  scene.add(new THREE.HemisphereLight(0xfff9e8, 0x9b8054, 2.2));
  const sun = new THREE.DirectionalLight(0xfff1d0, 3.0);
  sun.position.set(-30, 70, 20);
  scene.add(sun);

  buildOcean();
  buildZonePlatforms();
  buildBayLandmark();
  buildManorLandmark();
  buildFactoryLandmark();
  buildClockTower();
  const enemyLoads = buildInteractiveWorld();
  player = makeCharacter();
  const horizontalDistance = CAMERA_DISTANCE * Math.cos(cameraPitch);
  camera.position.copy(player.position).add(new THREE.Vector3(
    Math.sin(cameraYaw) * horizontalDistance,
    Math.sin(cameraPitch) * CAMERA_DISTANCE,
    Math.cos(cameraYaw) * horizontalDistance,
  ));
  camera.lookAt(player.position.x, player.position.y + 1.7, player.position.z);
  localizeStaticLabels();
  bindControls();
  window.addEventListener("resize", resizeRenderer);
  document.addEventListener("visibilitychange", () => {
    if (document.hidden) {
      window.cancelAnimationFrame(frameHandle);
      frameHandle = 0;
      lastFrame = 0;
      return;
    }
    if (!frameHandle) frameHandle = window.requestAnimationFrame(render);
  });
  frameHandle = window.requestAnimationFrame(render);
  void loadServerState();
  loadIslandAssets(enemyLoads).catch((error) => {
    console.error("Sunny Island 3D assets could not be initialized.", error);
    showMessage("Some island models could not load; refresh to try again.", 0);
  });
}

init();
