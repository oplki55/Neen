(() => {
  const layer = document.querySelector("[data-falling-flowers]");
  if (!(layer instanceof HTMLElement)) return;

  const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)");
  let currentCount = -1;

  const randomBetween = (min, max) => min + Math.random() * (max - min);
  const populateFlowers = () => {
    const targetCount = reducedMotion.matches
      ? 0
      : window.innerWidth <= 600
        ? 21
        : window.innerWidth <= 1050
          ? 33
          : 48;
    if (targetCount === currentCount) return;
    currentCount = targetCount;

    const flowers = document.createDocumentFragment();
    for (let index = 0; index < targetCount; index += 1) {
      const flower = document.createElement("span");
      const shape = index % 4 === 0 ? "bloom" : "petal";
      const spinDirection = Math.random() < 0.5 ? -1 : 1;
      const duration = randomBetween(17, 29);
      flower.className = `falling-flower falling-flower--${shape}`;
      flower.style.setProperty("--fall-x", `${randomBetween(3, 97)}%`);
      flower.style.setProperty("--fall-size", `${randomBetween(13, shape === "bloom" ? 23 : 19)}px`);
      flower.style.setProperty("--fall-opacity", randomBetween(0.24, 0.48).toFixed(2));
      flower.style.setProperty("--fall-duration", `${duration.toFixed(2)}s`);
      flower.style.setProperty("--fall-delay", `${-randomBetween(0, duration).toFixed(2)}s`);
      flower.style.setProperty("--fall-drift-one", `${randomBetween(-54, 54).toFixed(1)}px`);
      flower.style.setProperty("--fall-drift-two", `${randomBetween(-68, 68).toFixed(1)}px`);
      flower.style.setProperty("--fall-drift-three", `${randomBetween(-42, 42).toFixed(1)}px`);
      flower.style.setProperty("--fall-spin-start", `${randomBetween(0, 360).toFixed(0)}deg`);
      flower.style.setProperty("--fall-spin-mid", `${(spinDirection * randomBetween(70, 190)).toFixed(0)}deg`);
      flower.style.setProperty("--fall-spin-end", `${(spinDirection * randomBetween(230, 520)).toFixed(0)}deg`);
      flowers.append(flower);
    }
    layer.replaceChildren(flowers);
    layer.classList.toggle("is-paused", document.hidden);
  };

  const syncVisibility = () => layer.classList.toggle("is-paused", document.hidden);
  populateFlowers();
  window.addEventListener("resize", populateFlowers, { passive: true });
  document.addEventListener("visibilitychange", syncVisibility);
  reducedMotion.addEventListener("change", populateFlowers);
})();

(async () => {
  const canvas = document.querySelector("[data-loot-scene]");
  const lootSection = canvas?.closest("[data-loot-section]");
  if (!(canvas instanceof HTMLCanvasElement) || !lootSection) return;

  const scriptElement = document.currentScript || document.querySelector('script[src*="hero-loot.js"]');
  const assetBaseUrl = scriptElement?.src || window.location.href;
  const spriteSources = {
    coin: new URL("./assets/hero-loot/gold-coin.webp", assetBaseUrl).href,
    crate: new URL("./assets/hero-loot/loot-crate.webp", assetBaseUrl).href,
    backpack: new URL("./assets/hero-loot/backpack.webp", assetBaseUrl).href,
    ak47: new URL("./assets/hero-loot/weapon-ak47.webp", assetBaseUrl).href,
    mp40: new URL("./assets/hero-loot/weapon-mp40.webp", assetBaseUrl).href,
    stickerSheet: new URL("./icone/WhatsApp Image 2026-10-29 at 6.55.30 PM.webp", assetBaseUrl).href,
    snoopStickers: Array.from({ length: 9 }, (_, index) => new URL(
      `./assets/hero-loot/snoop-sticker-${String(index + 1).padStart(2, "0")}.webp`,
      assetBaseUrl,
    ).href),
  };
  const stickerAtlas = {
    heart: { x: 713, y: 111, width: 62, height: 62 },
    exclamation: { x: 1187, y: 139, width: 61, height: 68 },
  };
  const loadSpriteImage = (source, label) => new Promise((resolve, reject) => {
    const image = new window.Image();
    image.onload = () => resolve(image);
    image.onerror = (error) => reject(new Error(`Could not load the ${label} image at ${source}.`, { cause: error }));
    image.src = source;
  });

  const motionPreference = window.matchMedia("(prefers-reduced-motion: reduce)");
  const waitForLootSection = () => new Promise((resolve) => {
    if (!("IntersectionObserver" in window)) {
      resolve(true);
      return;
    }
    const observer = new IntersectionObserver(([entry]) => {
      if (!entry.isIntersecting) return;
      observer.disconnect();
      window.removeEventListener("pagehide", cancelWaiting);
      resolve(true);
    }, { rootMargin: "180px 0px" });
    const cancelWaiting = () => {
      observer.disconnect();
      resolve(false);
    };
    window.addEventListener("pagehide", cancelWaiting, { once: true });
    observer.observe(lootSection);
  });
  const vertexShaderSource = `
    attribute vec3 aPosition;
    attribute vec3 aNormal;
    attribute vec3 aColor;
    uniform mat4 uProjection;
    uniform vec3 uPosition;
    uniform vec3 uRotation;
    uniform float uScale;
    uniform mediump float uCameraZ;
    varying mediump vec3 vNormal;
    varying mediump vec3 vColor;
    varying mediump vec3 vWorldPosition;

    vec3 rotateVertex(vec3 value) {
      float sx = sin(uRotation.x), cx = cos(uRotation.x);
      float sy = sin(uRotation.y), cy = cos(uRotation.y);
      float sz = sin(uRotation.z), cz = cos(uRotation.z);
      vec3 rx = vec3(value.x, value.y * cx - value.z * sx, value.y * sx + value.z * cx);
      vec3 ry = vec3(rx.x * cy + rx.z * sy, rx.y, -rx.x * sy + rx.z * cy);
      return vec3(ry.x * cz - ry.y * sz, ry.x * sz + ry.y * cz, ry.z);
    }

    void main() {
      vec3 rotated = rotateVertex(aPosition);
      vec3 worldPosition = rotated * uScale + uPosition;
      vWorldPosition = worldPosition;
      vNormal = normalize(rotateVertex(aNormal));
      vColor = aColor;
      gl_Position = uProjection * vec4(worldPosition.xy, worldPosition.z - uCameraZ, 1.0);
    }
  `;
  const fragmentShaderSource = `
    precision mediump float;
    uniform float uOpacity;
    uniform float uCameraZ;
    varying mediump vec3 vNormal;
    varying mediump vec3 vColor;
    varying mediump vec3 vWorldPosition;

    void main() {
      vec3 normal = normalize(vNormal);
      vec3 lightDirection = normalize(vec3(-0.45, 0.72, 0.68));
      vec3 viewDirection = normalize(vec3(0.0, 0.0, uCameraZ) - vWorldPosition);
      float diffuse = max(dot(normal, lightDirection), 0.0);
      float rim = pow(1.0 - max(dot(normal, viewDirection), 0.0), 2.4);
      vec3 halfVector = normalize(lightDirection + viewDirection);
      float specular = pow(max(dot(normal, halfVector), 0.0), 28.0);
      vec3 litColor = vColor * (0.46 + diffuse * 0.88);
      litColor += vec3(0.82, 0.12, 0.46) * rim * 0.62;
      litColor += vec3(1.0, 0.86, 0.98) * specular * 0.58;
      gl_FragColor = vec4(litColor, uOpacity);
    }
  `;
  const spriteVertexShaderSource = `
    attribute vec3 aPosition;
    attribute vec2 aTexCoord;
    uniform mat4 uProjection;
    uniform vec3 uPosition;
    uniform vec3 uRotation;
    uniform vec2 uScale;
    uniform float uCameraZ;
    varying mediump vec2 vTexCoord;

    vec3 rotateVertex(vec3 value) {
      float sx = sin(uRotation.x), cx = cos(uRotation.x);
      float sy = sin(uRotation.y), cy = cos(uRotation.y);
      float sz = sin(uRotation.z), cz = cos(uRotation.z);
      vec3 rx = vec3(value.x, value.y * cx - value.z * sx, value.y * sx + value.z * cx);
      vec3 ry = vec3(rx.x * cy + rx.z * sy, rx.y, -rx.x * sy + rx.z * cy);
      return vec3(ry.x * cz - ry.y * sz, ry.x * sz + ry.y * cz, ry.z);
    }

    void main() {
      vec3 scaled = vec3(aPosition.x * uScale.x, aPosition.y * uScale.y, aPosition.z);
      vec3 worldPosition = rotateVertex(scaled) + uPosition;
      gl_Position = uProjection * vec4(worldPosition.xy, worldPosition.z - uCameraZ, 1.0);
      vTexCoord = aTexCoord;
    }
  `;
  const spriteFragmentShaderSource = `
    precision mediump float;
    uniform sampler2D uTexture;
    uniform float uOpacity;
    uniform float uPreserveColor;
    uniform vec2 uTexelSize;
    varying mediump vec2 vTexCoord;

    void main() {
      vec4 spriteColor = texture2D(uTexture, vTexCoord);
      vec2 offset = uTexelSize * 1.25;
      float neighborAlpha = max(
        max(texture2D(uTexture, vTexCoord + vec2(offset.x, 0.0)).a, texture2D(uTexture, vTexCoord - vec2(offset.x, 0.0)).a),
        max(texture2D(uTexture, vTexCoord + vec2(0.0, offset.y)).a, texture2D(uTexture, vTexCoord - vec2(0.0, offset.y)).a)
      );
      float edgeGlow = max(0.0, neighborAlpha - spriteColor.a) * 0.62 * (1.0 - uPreserveColor);
      float alpha = max(spriteColor.a, edgeGlow);
      if (alpha < 0.015) discard;
      vec3 adjustedColor = clamp(
        (spriteColor.rgb - vec3(0.08)) * 1.12 + vec3(0.105, 0.09, 0.115),
        0.0,
        1.0
      );
      vec3 visibleColor = mix(adjustedColor, spriteColor.rgb, uPreserveColor);
      vec3 glowColor = mix(visibleColor, vec3(0.84, 0.44, 1.0), min(edgeGlow * 0.85, 0.45));
      gl_FragColor = vec4(glowColor, alpha * uOpacity);
    }
  `;
  const particleVertexShaderSource = `
    attribute vec3 aPosition;
    attribute vec3 aColor;
    attribute float aSize;
    uniform mat4 uProjection;
    uniform float uTime;
    uniform float uPixelRatio;
    uniform float uCameraZ;
    varying mediump vec3 vColor;
    varying mediump float vFade;

    void main() {
      vec3 point = aPosition;
      point.y += sin(uTime * 0.24 + aPosition.x * 2.7) * 0.11;
      vec4 viewPosition = vec4(point.x, point.y, point.z - uCameraZ, 1.0);
      gl_Position = uProjection * viewPosition;
      gl_PointSize = clamp(aSize * uPixelRatio * (uCameraZ - point.z) * 0.12, 1.0, 5.2);
      vColor = aColor;
      vFade = 0.42 + 0.3 * sin(uTime * 0.8 + aPosition.y * 3.0);
    }
  `;
  const particleFragmentShaderSource = `
    precision mediump float;
    varying mediump vec3 vColor;
    varying mediump float vFade;
    void main() {
      vec2 point = gl_PointCoord - 0.5;
      float glow = 1.0 - smoothstep(0.0, 0.25, dot(point, point));
      gl_FragColor = vec4(vColor, glow * vFade);
    }
  `;

  const diamondGeometry = () => {
    const ringCount = 8;
    const lower = [];
    const upper = [];
    for (let index = 0; index < ringCount; index += 1) {
      const angle = (index / ringCount) * Math.PI * 2;
      const variation = index % 2 === 0 ? 1 : 0.84;
      lower.push([Math.cos(angle) * 0.48 * variation, -0.16, Math.sin(angle) * 0.48 * variation]);
      upper.push([Math.cos(angle + 0.08) * 0.35 * variation, 0.27, Math.sin(angle + 0.08) * 0.35 * variation]);
    }

    const triangles = [];
    const bottom = [0, -0.68, 0];
    const top = [0, 0.72, 0];
    for (let index = 0; index < ringCount; index += 1) {
      const next = (index + 1) % ringCount;
      triangles.push([bottom, lower[next], lower[index]]);
      triangles.push([lower[index], lower[next], upper[index]]);
      triangles.push([lower[next], upper[next], upper[index]]);
      triangles.push([upper[index], upper[next], top]);
    }
    return flattenFaces(triangles, ["#ff3f9b", "#c21863", "#a24bd0", "#ff77b7"]);
  };

  const spriteGeometry = () => new Float32Array([
    -0.5, -0.5, 0, 0, 0,
    0.5, -0.5, 0, 1, 0,
    0.5, 0.5, 0, 1, 1,
    -0.5, -0.5, 0, 0, 0,
    0.5, 0.5, 0, 1, 1,
    -0.5, 0.5, 0, 0, 1,
  ]);

  function flattenFaces(triangles, palette) {
    const positions = [];
    let triangleIndex = 0;
    for (const [a, b, c, explicitColor] of triangles) {
      const edgeA = [b[0] - a[0], b[1] - a[1], b[2] - a[2]];
      const edgeB = [c[0] - a[0], c[1] - a[1], c[2] - a[2]];
      const normal = [
        edgeA[1] * edgeB[2] - edgeA[2] * edgeB[1],
        edgeA[2] * edgeB[0] - edgeA[0] * edgeB[2],
        edgeA[0] * edgeB[1] - edgeA[1] * edgeB[0],
      ];
      const length = Math.hypot(...normal) || 1;
      const color = hexToRgb(explicitColor || palette[triangleIndex % palette.length]);
      for (const vertex of [a, b, c]) {
        positions.push(...vertex, normal[0] / length, normal[1] / length, normal[2] / length, ...color);
      }
      triangleIndex += 1;
    }
    return new Float32Array(positions);
  }

  function hexToRgb(hex) {
    const value = Number.parseInt(hex.slice(1), 16);
    return [((value >> 16) & 255) / 255, ((value >> 8) & 255) / 255, (value & 255) / 255];
  }

  const compileShader = (gl, type, source, label) => {
    const shader = gl.createShader(type);
    if (!shader) throw new Error(`Could not allocate the ${label} shader.`);
    gl.shaderSource(shader, source);
    gl.compileShader(shader);
    if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) {
      const message = gl.getShaderInfoLog(shader) || "No shader compiler log was provided.";
      gl.deleteShader(shader);
      throw new Error(`${label} shader compilation failed: ${message}`);
    }
    return shader;
  };

  const createProgram = (gl, vertexSource, fragmentSource, label) => {
    const program = gl.createProgram();
    if (!program) throw new Error(`Could not allocate the ${label} program.`);
    const vertexShader = compileShader(gl, gl.VERTEX_SHADER, vertexSource, `${label} vertex`);
    const fragmentShader = compileShader(gl, gl.FRAGMENT_SHADER, fragmentSource, `${label} fragment`);
    gl.attachShader(program, vertexShader);
    gl.attachShader(program, fragmentShader);
    gl.linkProgram(program);
    gl.deleteShader(vertexShader);
    gl.deleteShader(fragmentShader);
    if (!gl.getProgramParameter(program, gl.LINK_STATUS)) {
      const message = gl.getProgramInfoLog(program) || "No program linker log was provided.";
      gl.deleteProgram(program);
      throw new Error(`${label} program link failed: ${message}`);
    }
    return program;
  };

  const createBuffer = (gl, data, label, usage = gl.STATIC_DRAW) => {
    const buffer = gl.createBuffer();
    if (!buffer) throw new Error(`Could not allocate the ${label} buffer.`);
    gl.bindBuffer(gl.ARRAY_BUFFER, buffer);
    gl.bufferData(gl.ARRAY_BUFFER, data, usage);
    return buffer;
  };

  const getUniform = (gl, program, name, label) => {
    const location = gl.getUniformLocation(program, name);
    if (location === null) throw new Error(`${label} uniform '${name}' is unavailable.`);
    return location;
  };

  const getAttribute = (gl, program, name, label) => {
    const location = gl.getAttribLocation(program, name);
    if (location < 0) throw new Error(`${label} attribute '${name}' is unavailable.`);
    return location;
  };

  const createTexture = (gl, image, label) => {
    const texture = gl.createTexture();
    if (!texture) throw new Error(`Could not allocate the ${label} texture.`);
    const maxTextureSize = Math.min(1024, gl.getParameter(gl.MAX_TEXTURE_SIZE) || 1024);
    const imageWidth = image.naturalWidth || image.width;
    const imageHeight = image.naturalHeight || image.height;
    const imageSize = Math.max(imageWidth, imageHeight);
    let textureWidth = imageWidth;
    let textureHeight = imageHeight;
    let textureSource = image;
    if (imageSize > maxTextureSize) {
      const scale = maxTextureSize / imageSize;
      const scaledCanvas = document.createElement("canvas");
      scaledCanvas.width = Math.max(1, Math.round(imageWidth * scale));
      scaledCanvas.height = Math.max(1, Math.round(imageHeight * scale));
      const context = scaledCanvas.getContext("2d", { alpha: true });
      if (!context) throw new Error(`Could not resize the ${label} texture source.`);
      context.drawImage(image, 0, 0, scaledCanvas.width, scaledCanvas.height);
      textureSource = scaledCanvas;
      textureWidth = scaledCanvas.width;
      textureHeight = scaledCanvas.height;
    }

    gl.bindTexture(gl.TEXTURE_2D, texture);
    gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, true);
    gl.pixelStorei(gl.UNPACK_PREMULTIPLY_ALPHA_WEBGL, false);
    gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, textureSource);
    gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, false);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
    const error = gl.getError();
    if (error !== gl.NO_ERROR) {
      gl.deleteTexture(texture);
      throw new Error(`Could not upload the ${label} texture (WebGL error ${error}).`);
    }
    return {
      texture,
      aspect: imageWidth / imageHeight,
      texelSize: [1 / textureWidth, 1 / textureHeight],
      preserveColor: label.startsWith("sticker-"),
    };
  };
  const createAtlasSprite = (image, bounds) => {
    const spriteCanvas = document.createElement("canvas");
    spriteCanvas.width = bounds.width;
    spriteCanvas.height = bounds.height;
    const context = spriteCanvas.getContext("2d", { willReadFrequently: true });
    if (!context) throw new Error("Could not prepare a cropped sticker sprite.");
    context.drawImage(image, bounds.x, bounds.y, bounds.width, bounds.height, 0, 0, bounds.width, bounds.height);
    const imageData = context.getImageData(0, 0, bounds.width, bounds.height);
    const { data } = imageData;
    const pixelCount = bounds.width * bounds.height;
    const background = new Uint8Array(pixelCount);
    const queue = new Int32Array(pixelCount);
    let queueStart = 0;
    let queueEnd = 0;
    const addBackgroundPixel = (pixelIndex) => {
      if (background[pixelIndex]) return;
      const offset = pixelIndex * 4;
      const brightness = Math.max(data[offset], data[offset + 1], data[offset + 2]);
      if (brightness > 64) return;
      background[pixelIndex] = 1;
      queue[queueEnd] = pixelIndex;
      queueEnd += 1;
    };

    for (let x = 0; x < bounds.width; x += 1) {
      addBackgroundPixel(x);
      addBackgroundPixel((bounds.height - 1) * bounds.width + x);
    }
    for (let y = 0; y < bounds.height; y += 1) {
      addBackgroundPixel(y * bounds.width);
      addBackgroundPixel(y * bounds.width + bounds.width - 1);
    }
    while (queueStart < queueEnd) {
      const pixelIndex = queue[queueStart];
      queueStart += 1;
      const x = pixelIndex % bounds.width;
      const y = Math.floor(pixelIndex / bounds.width);
      if (x > 0) addBackgroundPixel(pixelIndex - 1);
      if (x + 1 < bounds.width) addBackgroundPixel(pixelIndex + 1);
      if (y > 0) addBackgroundPixel(pixelIndex - bounds.width);
      if (y + 1 < bounds.height) addBackgroundPixel(pixelIndex + bounds.width);
    }

    for (let pixelIndex = 0; pixelIndex < pixelCount; pixelIndex += 1) {
      if (!background[pixelIndex]) continue;
      const offset = pixelIndex * 4;
      const brightness = Math.max(data[offset], data[offset + 1], data[offset + 2]);
      data[offset + 3] = Math.round(data[offset + 3] * Math.max(0, Math.min(1, (brightness - 12) / 52)));
    }
    context.putImageData(imageData, 0, 0);
    return spriteCanvas;
  };
  const loadStickerTextures = async (gl, source, additionalSources) => {
    const image = await loadSpriteImage(source, "sticker sheet");
    let textures;
    try {
      textures = Object.fromEntries(Object.entries(stickerAtlas).map(([name, bounds]) => [
        name,
        createTexture(gl, createAtlasSprite(image, bounds), `sticker-${name}`),
      ]));
    } finally {
      image.onload = null;
      image.onerror = null;
    }
    const additionalTextures = await Promise.all(additionalSources.map((stickerSource, index) =>
      loadSpriteTexture(gl, stickerSource, `sticker-snoop-${index + 1}`),
    ));
    return Object.assign(
      textures,
      Object.fromEntries(additionalTextures.map((texture, index) => [`snoop-${index + 1}`, texture])),
    );
  };
  const loadSpriteTexture = async (gl, source, label) => {
    const image = await loadSpriteImage(source, label);
    try {
      return createTexture(gl, image, label);
    } finally {
      image.onload = null;
      image.onerror = null;
    }
  };
  const loadLayeredCoinTexture = async (gl, source) => {
    const image = await loadSpriteImage(source, "coin");
    try {
      const imageWidth = image.naturalWidth || image.width;
      const imageHeight = image.naturalHeight || image.height;
      const canvas = document.createElement("canvas");
      canvas.width = Math.round(imageWidth * 1.5);
      canvas.height = Math.round(imageHeight * 1.5);
      const context = canvas.getContext("2d", { alpha: true });
      if (!context) throw new Error("Could not prepare the layered coin texture.");

      context.globalAlpha = 0.24;
      context.drawImage(image, 0, 0, canvas.width, canvas.height);
      context.globalAlpha = 1;
      const innerWidth = imageWidth * 1.24;
      const innerHeight = imageHeight * 1.24;
      context.drawImage(
        image,
        (canvas.width - innerWidth) / 2,
        (canvas.height - innerHeight) / 2,
        innerWidth,
        innerHeight,
      );
      return createTexture(gl, canvas, "coin");
    } finally {
      image.onload = null;
      image.onerror = null;
    }
  };

  const createProjection = (fieldOfView, aspect, near, far) => {
    const scale = 1 / Math.tan(fieldOfView / 2);
    const range = 1 / (near - far);
    return new Float32Array([
      scale / aspect, 0, 0, 0,
      0, scale, 0, 0,
      0, 0, (near + far) * range, -1,
      0, 0, near * far * 2 * range, 0,
    ]);
  };

  const randomBetween = (min, max) => min + Math.random() * (max - min);
  const shuffle = (values) => {
    const shuffled = values.slice();
    for (let index = shuffled.length - 1; index > 0; index -= 1) {
      const swapIndex = Math.floor(Math.random() * (index + 1));
      [shuffled[index], shuffled[swapIndex]] = [shuffled[swapIndex], shuffled[index]];
    }
    return shuffled;
  };
  const createRainLayout = (count) => {
    const laneCount = Math.max(1, count);
    const columns = shuffle(Array.from({ length: count }, (_, index) => index));
    const rows = shuffle(Array.from({ length: count }, (_, index) => index));
    let spawnLanes = shuffle(Array.from({ length: laneCount }, (_, index) => index));
    let spawnLaneIndex = 0;
    const lanePosition = (lane) => 0.035 + ((lane + randomBetween(0.14, 0.86)) / laneCount) * 0.93;
    const nextX = (...previousXs) => {
      let candidate = previousXs[0] ?? -1;
      const minimumSeparation = Math.min(0.12, 0.5 / laneCount);
      for (let attempt = 0; attempt < laneCount * 2; attempt += 1) {
        if (spawnLaneIndex >= spawnLanes.length) {
          spawnLanes = shuffle(Array.from({ length: laneCount }, (_, index) => index));
          spawnLaneIndex = 0;
        }
        candidate = lanePosition(spawnLanes[spawnLaneIndex]);
        spawnLaneIndex += 1;
        if (previousXs.every((previousX) => Math.abs(candidate - previousX) >= minimumSeparation)) {
          return candidate;
        }
      }
      return candidate;
    };

    return {
      positions: Array.from({ length: count }, (_, index) => ({
        screenX: lanePosition(columns[index]),
        screenY: 0.025 + ((rows[index] + randomBetween(0.14, 0.86)) / laneCount) * 0.95,
      })),
      nextX,
    };
  };
  const advanceRain = (item, delta, nextX) => {
    item.screenX += item.driftX * delta;
    item.screenY += item.fallSpeed * delta;
    if (item.screenX < 0.02) item.screenX = 0.98;
    if (item.screenY > 1.06) {
      item.screenX = nextX(item.screenX, item.lastSpawnX);
      item.lastSpawnX = item.screenX;
      item.screenY = randomBetween(-0.2, -0.04);
    }
  };
  const isPortraitSafeZone = (screenX, screenY) => {
    const mobile = width < 760;
    const faceLeft = mobile ? 0.32 : 0.31;
    const faceRight = mobile ? 0.68 : 0.55;
    const faceTop = 0.04;
    const faceBottom = mobile ? 0.38 : 0.34;
    return screenX >= faceLeft && screenX <= faceRight
      && screenY >= faceTop && screenY <= faceBottom;
  };
  const lootCountElement = document.querySelector("[data-loot-count]");
  const lootNextElement = document.querySelector("[data-loot-next]");
  const lootMessageElement = document.querySelector("[data-loot-message]");
  const lootCoinCount = 20;
  const collectionEffectVertices = new Float32Array(lootCoinCount * 12 * 7);
  const lootCoinValue = 0.01;
  const lootGroupValue = lootCoinCount * lootCoinValue;
  const lootGroupDuration = 60 * 60 * 1000;
  const localCollectionKey = "neen-hero-loot-preview-v1";
  const getCurrentGroupStart = () => Math.floor(Date.now() / lootGroupDuration) * lootGroupDuration;
  const getGroupId = (start) => `utc-hour-${new Date(start).toISOString().slice(0, 13)}`;
  let groupStart = getCurrentGroupStart();
  let groupId = getGroupId(groupStart);

  const loadCollectedIds = (currentGroupId) => {
    try {
      const saved = JSON.parse(window.localStorage.getItem(localCollectionKey) || "null");
      if (saved?.groupId !== currentGroupId || !Array.isArray(saved.collectedIds)) return new Set();
      const coinIdPrefix = `${currentGroupId}:coin:`;
      return new Set(saved.collectedIds.filter((id) => {
        if (typeof id !== "string" || !id.startsWith(coinIdPrefix)) return false;
        const suffix = id.slice(coinIdPrefix.length);
        const sequence = Number(suffix);
        return /^\d{2}$/.test(suffix)
          && String(sequence).padStart(2, "0") === suffix
          && sequence >= 1
          && sequence <= lootCoinCount;
      }));
    } catch (error) {
      console.warn("The local NEEN loot preview could not read saved collection state.", error);
      return new Set();
    }
  };

  let collectedIds = loadCollectedIds(groupId);
  let batchEntranceStart = performance.now();
  let batchTimer = 0;
  let coinObjects = [];
  let coinRainLayout;
  let objectRainLayout;
  let stickerRainLayout;
  let collectionEffects = [];
  let objects = [];
  let stickerObjects = [];

  const saveCollectedIds = () => {
    try {
      window.localStorage.setItem(localCollectionKey, JSON.stringify({
        groupId,
        collectedIds: [...collectedIds],
      }));
    } catch (error) {
      console.warn("The local NEEN loot preview could not save collection state.", error);
    }
  };

  const syncCollectionUi = () => {
    if (lootCountElement) lootCountElement.textContent = String(collectedIds.size);
    const coinValueElement = document.querySelector("[data-loot-coin-value]");
    const groupValueElement = document.querySelector("[data-loot-group-value]");
    if (coinValueElement) coinValueElement.textContent = lootCoinValue.toFixed(2);
    if (groupValueElement) groupValueElement.textContent = lootGroupValue.toFixed(2);
  };

  const createCoinBatch = () => {
    coinRainLayout = createRainLayout(lootCoinCount);
    return coinRainLayout.positions.map(({ screenX, screenY }, index) => {
      const mobile = width < 760;
      const id = `${groupId}:coin:${String(index + 1).padStart(2, "0")}`;
      return {
        id,
        value: lootCoinValue,
        screenX,
        screenY,
        lastSpawnX: screenX,
        z: 1.6,
        scale: mobile ? 0.4 : 0.54,
        rotation: [0.12, randomBetween(0, Math.PI * 2), 0.04],
        rotationSpeed: [0.08, randomBetween(0.35, 0.7), 0.03],
        fallSpeed: randomBetween(0.12, 0.2),
        driftX: randomBetween(-0.012, -0.004),
        phase: randomBetween(0, Math.PI * 2),
        collected: collectedIds.has(id),
        collecting: false,
        collectionProgress: 0,
      };
    });
  };

  const createLoot = (kind, index, position, stickerIndex) => ({
    kind,
    spriteIndex: kind === "weapon"
      ? Math.floor(Math.random() * 2)
      : kind === "sticker"
        ? stickerIndex ?? Math.floor(Math.random() * stickerTextures.length)
        : 0,
    screenX: position.screenX,
    screenY: position.screenY,
    lastSpawnX: position.screenX,
    z: randomBetween(0.6, 1.8),
    scale: kind === "diamond"
      ? randomBetween(width < 760 ? 0.12 : 0.2, width < 760 ? 0.25 : 0.43)
      : kind === "weapon"
        ? randomBetween(0.62, 0.9)
        : kind === "sticker"
          ? randomBetween(width < 760 ? 0.42 : 0.58, width < 760 ? 0.56 : 0.76)
        : randomBetween(0.48, 0.72),
    fallSpeed: randomBetween(0.11, 0.2),
    driftX: randomBetween(-0.014, -0.004),
    rotation: [randomBetween(-0.3, 0.3), randomBetween(0, Math.PI * 2), randomBetween(-0.3, 0.3)],
    rotationSpeed: [randomBetween(-0.12, 0.12), randomBetween(-0.3, 0.3), randomBetween(-0.08, 0.08)],
    phase: randomBetween(0, Math.PI * 2) + index,
    interactionPulse: 0,
    openProgress: 0,
    openTarget: 0,
  });
  const createStickerObjects = () => {
    stickerRainLayout = createRainLayout(motionPreference.matches ? 0 : stickerTextures.length);
    return stickerRainLayout.positions.map((position, index) => {
      const spawnPosition = { ...position };
      if (isPortraitSafeZone(spawnPosition.screenX, spawnPosition.screenY)) {
        spawnPosition.screenY = randomBetween(0.5, 0.78);
      }
      return createLoot("sticker", index, spawnPosition, index);
    });
  };

  let gl;
  let objectProgram;
  let particleProgram;
  let spriteProgram;
  let diamondBuffer;
  let spriteBuffer;
  let sparkBuffer;
  let particleBuffer;
  let itemTextures = {};
  let weaponTextures = [];
  let stickerTextures = [];
  let objectCount = 0;
  let particleCount = 0;
  let objectUniforms;
  let particleUniforms;
  let spriteUniforms;
  let objectAttributes;
  let particleAttributes;
  let spriteAttributes;
  let projection = createProjection(Math.PI / 3.8, 1, 0.1, 50);
  let width = 0;
  let height = 0;
  let pixelRatio = 1;
  let visible = false;
  let running = false;
  let frameHandle = 0;
  let resizeFrame = 0;
  let lastFrameTime = 0;
  let elapsedTime = 0;
  let pointerX = 0;
  let pointerY = 0;
  let targetPointerX = 0;
  let targetPointerY = 0;
  let pointerPageX = 0;
  let pointerPageY = 0;
  let pointerPositionPending = false;
  let sectionRect = null;
  let counterPos = { x: 0.9, y: 0.15 };
  const cameraZ = 10;

  const screenToWorld = (screenX, screenY, depth) => {
    const distance = cameraZ - depth;
    const halfHeight = Math.tan(Math.PI / 7.6) * distance;
    const halfWidth = halfHeight * (width / height);
    return {
      x: (screenX * 2 - 1) * halfWidth,
      y: (1 - screenY * 2) * halfHeight,
    };
  };

  const updateCounterPosition = () => {
    const bounds = lootCountElement?.getBoundingClientRect();
    if (!bounds || !sectionRect?.width || !sectionRect.height) {
      counterPos = { x: 0.9, y: 0.15 };
      return;
    }
    counterPos = {
      x: (bounds.left + bounds.width / 2 + window.scrollX - sectionRect.pageLeft) / sectionRect.width,
      y: (bounds.top + bounds.height / 2 + window.scrollY - sectionRect.pageTop) / sectionRect.height,
    };
  };

  const collectCoin = (coin) => {
    if (coin.collected || coin.collecting) return;
    coin.collected = true;
    coin.collecting = true;
    coin.collectionProgress = 0;
    collectionEffects.push({
      screenX: coin.screenX,
      screenY: coin.screenY,
      z: coin.z,
      startedAt: performance.now(),
    });
    collectedIds.add(coin.id);
    saveCollectedIds();
    syncCollectionUi();
    updateCounterPosition();
    if (lootMessageElement) {
      lootMessageElement.textContent = collectedIds.size === lootCoinCount
        ? "Preview complete. The next group appears at the next UTC hour. No reward is credited."
        : "Coin collected in this local preview. No balance or reward is credited.";
    }
    if (motionPreference.matches && gl) {
      coin.collecting = false;
      render(performance.now());
    }
  };

  const handleCanvasPointerDown = (event) => {
    const bounds = sectionRect;
    if (!bounds?.width || !bounds.height) return;
    const pointerX = event.pageX - bounds.pageLeft;
    const pointerY = event.pageY - bounds.pageTop;
    let nearestCoin = null;
    let nearestDistance = 28;
    for (const coin of coinObjects) {
      if (coin.collected || coin.collecting || isPortraitSafeZone(coin.screenX, coin.screenY)) continue;
      const screenX = coin.screenX * bounds.width;
      const screenY = coin.screenY * bounds.height;
      const distance = Math.hypot(pointerX - screenX, pointerY - screenY);
      if (distance < nearestDistance) {
        nearestDistance = distance;
        nearestCoin = coin;
      }
    }
    if (nearestCoin) {
      collectCoin(nearestCoin);
      return;
    }

    let nearestObject = null;
    nearestDistance = 36;
    for (const object of objects) {
      if (object.kind === "sticker" || isPortraitSafeZone(object.screenX, object.screenY)) continue;
      const screenX = object.screenX * bounds.width;
      const screenY = object.screenY * bounds.height;
      const distance = Math.hypot(pointerX - screenX, pointerY - screenY);
      if (distance < nearestDistance) {
        nearestDistance = distance;
        nearestObject = object;
      }
    }
    if (!nearestObject) return;
    nearestObject.interactionPulse = 1;
    if (nearestObject.kind === "crate") nearestObject.openTarget = nearestObject.openTarget ? 0 : 1;
    if (motionPreference.matches) {
      nearestObject.openProgress = nearestObject.openTarget;
      nearestObject.interactionPulse = 0.35;
      render(performance.now());
    }
  };

  const handleCanvasKeyDown = (event) => {
    if (event.key !== "Enter" && event.key !== " ") return;
    const nextCoin = coinObjects.find((coin) =>
      !coin.collected && !coin.collecting && !isPortraitSafeZone(coin.screenX, coin.screenY),
    );
    if (!nextCoin) return;
    event.preventDefault();
    collectCoin(nextCoin);
  };

  const updateBatchClock = () => {
    if (batchTimer) window.clearTimeout(batchTimer);
    const now = Date.now();
    const nextGroupStart = getCurrentGroupStart();
    if (nextGroupStart !== groupStart) {
      groupStart = nextGroupStart;
      groupId = getGroupId(groupStart);
      collectedIds = loadCollectedIds(groupId);
      coinObjects = createCoinBatch();
      batchEntranceStart = performance.now();
      syncCollectionUi();
      if (lootMessageElement) {
        lootMessageElement.textContent = "A new local preview group is here. No balance or reward is credited.";
      }
      if (!running && gl) render(performance.now());
    }

    const secondsRemaining = Math.max(0, Math.ceil((groupStart + lootGroupDuration - now) / 1000));
    if (lootNextElement) {
      const minutes = Math.floor(secondsRemaining / 60);
      const seconds = secondsRemaining % 60;
      lootNextElement.textContent = `${String(minutes).padStart(2, "0")}:${String(seconds).padStart(2, "0")}`;
    }
    batchTimer = window.setTimeout(updateBatchClock, Math.min(1000, groupStart + lootGroupDuration - now));
  };

  const drawParticles = (time, buffer = particleBuffer, count = particleCount) => {
    gl.useProgram(particleProgram);
    gl.bindBuffer(gl.ARRAY_BUFFER, buffer);
    const stride = 7 * Float32Array.BYTES_PER_ELEMENT;
    gl.enableVertexAttribArray(particleAttributes.position);
    gl.vertexAttribPointer(particleAttributes.position, 3, gl.FLOAT, false, stride, 0);
    gl.enableVertexAttribArray(particleAttributes.color);
    gl.vertexAttribPointer(particleAttributes.color, 3, gl.FLOAT, false, stride, 3 * Float32Array.BYTES_PER_ELEMENT);
    gl.enableVertexAttribArray(particleAttributes.size);
    gl.vertexAttribPointer(particleAttributes.size, 1, gl.FLOAT, false, stride, 6 * Float32Array.BYTES_PER_ELEMENT);
    gl.uniformMatrix4fv(particleUniforms.projection, false, projection);
    gl.uniform1f(particleUniforms.time, time);
    gl.uniform1f(particleUniforms.pixelRatio, pixelRatio);
    gl.uniform1f(particleUniforms.cameraZ, cameraZ);
    gl.drawArrays(gl.POINTS, 0, count);
  };

  const drawCollectionEffects = (time) => {
    let activeEffectCount = 0;
    let vertexCount = 0;
    for (let effectIndex = 0; effectIndex < collectionEffects.length; effectIndex += 1) {
      const effect = collectionEffects[effectIndex];
      const elapsed = time - effect.startedAt;
      if (elapsed >= 650) continue;
      collectionEffects[activeEffectCount] = effect;
      activeEffectCount += 1;
      const age = Math.max(0, elapsed / 650);
      const halfHeight = Math.tan(Math.PI / 7.6) * (cameraZ - effect.z);
      const halfWidth = halfHeight * (width / height);
      for (let index = 0; index < 12; index += 1) {
        const angle = (index / 12) * Math.PI * 2;
        const distance = 0.018 + age * 0.09;
        const screenX = effect.screenX + Math.cos(angle) * distance;
        const screenY = effect.screenY + Math.sin(angle) * distance;
        const offset = vertexCount * 7;
        const size = (1 - age) * (2.6 + (index % 3) * 0.6);
        collectionEffectVertices[offset] = (screenX * 2 - 1) * halfWidth;
        collectionEffectVertices[offset + 1] = (1 - screenY * 2) * halfHeight;
        collectionEffectVertices[offset + 2] = effect.z;
        collectionEffectVertices[offset + 3] = 1;
        collectionEffectVertices[offset + 4] = 0.76;
        collectionEffectVertices[offset + 5] = 0.28;
        collectionEffectVertices[offset + 6] = size;
        vertexCount += 1;
      }
    }
    collectionEffects.length = activeEffectCount;
    if (!vertexCount) return;
    gl.bindBuffer(gl.ARRAY_BUFFER, sparkBuffer);
    gl.bufferSubData(gl.ARRAY_BUFFER, 0, collectionEffectVertices);
    drawParticles(time * 0.001, sparkBuffer, vertexCount);
  };

  const bindObjectGeometry = (buffer) => {
    gl.bindBuffer(gl.ARRAY_BUFFER, buffer);
    const stride = 9 * Float32Array.BYTES_PER_ELEMENT;
    gl.enableVertexAttribArray(objectAttributes.position);
    gl.vertexAttribPointer(objectAttributes.position, 3, gl.FLOAT, false, stride, 0);
    gl.enableVertexAttribArray(objectAttributes.normal);
    gl.vertexAttribPointer(objectAttributes.normal, 3, gl.FLOAT, false, stride, 3 * Float32Array.BYTES_PER_ELEMENT);
    gl.enableVertexAttribArray(objectAttributes.color);
    gl.vertexAttribPointer(objectAttributes.color, 3, gl.FLOAT, false, stride, 6 * Float32Array.BYTES_PER_ELEMENT);
  };

  const render = (time) => {
    if (!gl || !width || !height) return;
    const frameInterval = width < 760 ? 1000 / 30 : 1000 / 45;
    if (running && lastFrameTime && time - lastFrameTime < frameInterval) {
      frameHandle = window.requestAnimationFrame(render);
      return;
    }
    const delta = lastFrameTime ? Math.min((time - lastFrameTime) * 0.001, 0.05) : 0;
    lastFrameTime = time;
    elapsedTime += delta;
    if (pointerPositionPending && sectionRect?.width && sectionRect.height) {
      targetPointerX = ((pointerPageX - sectionRect.pageLeft) / sectionRect.width - 0.5) * 2;
      targetPointerY = ((pointerPageY - sectionRect.pageTop) / sectionRect.height - 0.5) * -2;
    }
    pointerX += (targetPointerX - pointerX) * 0.035;
    pointerY += (targetPointerY - pointerY) * 0.035;

    gl.viewport(0, 0, canvas.width, canvas.height);
    gl.clear(gl.COLOR_BUFFER_BIT | gl.DEPTH_BUFFER_BIT);
    gl.enable(gl.DEPTH_TEST);
    gl.depthFunc(gl.LEQUAL);
    gl.enable(gl.BLEND);
    gl.blendFunc(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA);
    gl.depthMask(false);
    drawParticles(elapsedTime);
    drawCollectionEffects(time);
    gl.depthMask(true);

    let activeProgram = null;
    let activeBuffer = null;
    const drawMesh = (buffer, vertexCount, position, rotation, scale, opacity) => {
      if (activeProgram !== objectProgram) {
        gl.useProgram(objectProgram);
        gl.uniformMatrix4fv(objectUniforms.projection, false, projection);
        gl.uniform1f(objectUniforms.cameraZ, cameraZ);
        activeProgram = objectProgram;
        activeBuffer = null;
      }
      if (buffer !== activeBuffer) {
        bindObjectGeometry(buffer);
        activeBuffer = buffer;
      }
      gl.uniform3f(objectUniforms.position, position.x, position.y, position.z);
      gl.uniform3f(objectUniforms.rotation, rotation[0], rotation[1], rotation[2]);
      gl.uniform1f(objectUniforms.scale, scale);
      gl.uniform1f(objectUniforms.opacity, opacity);
      gl.drawArrays(gl.TRIANGLES, 0, vertexCount);
    };
    const drawSprite = (sprite, position, rotation, scale, opacity) => {
      if (activeProgram !== spriteProgram) {
        gl.useProgram(spriteProgram);
        gl.uniformMatrix4fv(spriteUniforms.projection, false, projection);
        gl.uniform1f(spriteUniforms.cameraZ, cameraZ);
        activeProgram = spriteProgram;
        activeBuffer = null;
      }
      if (activeBuffer !== spriteBuffer) {
        gl.bindBuffer(gl.ARRAY_BUFFER, spriteBuffer);
        const stride = 5 * Float32Array.BYTES_PER_ELEMENT;
        gl.enableVertexAttribArray(spriteAttributes.position);
        gl.vertexAttribPointer(spriteAttributes.position, 3, gl.FLOAT, false, stride, 0);
        gl.enableVertexAttribArray(spriteAttributes.texCoord);
        gl.vertexAttribPointer(
          spriteAttributes.texCoord,
          2,
          gl.FLOAT,
          false,
          stride,
          3 * Float32Array.BYTES_PER_ELEMENT,
        );
        activeBuffer = spriteBuffer;
      }
      gl.uniform3f(spriteUniforms.position, position.x, position.y, position.z);
      gl.uniform3f(spriteUniforms.rotation, rotation[0], rotation[1], rotation[2]);
      gl.uniform2f(spriteUniforms.scale, scale, scale / sprite.aspect);
      gl.uniform1f(spriteUniforms.opacity, opacity);
      gl.uniform1f(spriteUniforms.preserveColor, sprite.preserveColor ? 1 : 0);
      gl.uniform2f(spriteUniforms.texelSize, sprite.texelSize[0], sprite.texelSize[1]);
      gl.activeTexture(gl.TEXTURE0);
      gl.bindTexture(gl.TEXTURE_2D, sprite.texture);
      gl.uniform1i(spriteUniforms.texture, 0);
      gl.depthMask(false);
      gl.drawArrays(gl.TRIANGLES, 0, 6);
      gl.depthMask(true);
    };

    for (const object of objects) {
      advanceRain(object, delta, objectRainLayout.nextX);
      const position = screenToWorld(object.screenX, object.screenY, object.z);
      object.rotation[0] += object.rotationSpeed[0] * delta;
      object.rotation[1] += object.rotationSpeed[1] * delta;
      object.rotation[2] += object.rotationSpeed[2] * delta;
      object.interactionPulse = Math.max(0, object.interactionPulse - delta * 2.5);
      object.openProgress += (object.openTarget - object.openProgress) * Math.min(1, delta * 5);
      const opacity = object.kind === "sticker"
        ? 1
        : object.kind === "diamond"
          ? 0.88 + object.interactionPulse * 0.12
          : Math.min(1, 0.9 + object.interactionPulse * 0.1);
      const rotation = [
        object.rotation[0] + pointerY * 0.05,
        object.rotation[1] + pointerX * 0.08,
        object.rotation[2],
      ];
      if (isPortraitSafeZone(object.screenX, object.screenY)) continue;
      if (object.kind === "diamond") {
        drawMesh(diamondBuffer, 96, { ...position, z: object.z }, rotation, object.scale, opacity);
      } else {
        const sprite = object.kind === "weapon"
          ? weaponTextures[object.spriteIndex]
          : object.kind === "sticker"
            ? stickerTextures[object.spriteIndex]
          : object.kind === "crate"
            ? itemTextures.crate
            : itemTextures.backpack;
        const spriteScale = object.scale * (
          object.kind === "weapon" ? 1.3 : object.kind === "crate" ? 1.08 : object.kind === "sticker" ? 1.12 : 0.82
        ) * (object.kind === "crate" ? 1 + object.openProgress * 0.08 : 1);
        if (object.kind === "crate") rotation[0] -= object.openProgress * 0.65;
        drawSprite(sprite, { ...position, z: object.z }, rotation, spriteScale, opacity);
      }
    }

    for (const sticker of stickerObjects) {
      advanceRain(sticker, delta, stickerRainLayout.nextX);
      if (isPortraitSafeZone(sticker.screenX, sticker.screenY)) continue;
      sticker.rotation[0] += sticker.rotationSpeed[0] * delta;
      sticker.rotation[1] += sticker.rotationSpeed[1] * delta;
      sticker.rotation[2] += sticker.rotationSpeed[2] * delta;
      const position = screenToWorld(sticker.screenX, sticker.screenY, sticker.z);
      const rotation = [
        sticker.rotation[0] + pointerY * 0.05,
        sticker.rotation[1] + pointerX * 0.08,
        sticker.rotation[2],
      ];
      drawSprite(
        stickerTextures[sticker.spriteIndex],
        { ...position, z: sticker.z },
        rotation,
        sticker.scale * 1.12,
        1,
      );
    }

    const entryProgress = motionPreference.matches
      ? 1
      : Math.min(1, Math.max(0, (time - batchEntranceStart) / 900));
    const entryEase = 1 - (1 - entryProgress) ** 3;
    for (const [index, coin] of coinObjects.entries()) {
      if (coin.collected && !coin.collecting) continue;
      if (!coin.collecting) advanceRain(coin, delta, coinRainLayout.nextX);
      if (!coin.collecting && isPortraitSafeZone(coin.screenX, coin.screenY)) continue;
      coin.rotation[1] += coin.rotationSpeed[1] * delta;
      coin.rotation[2] += coin.rotationSpeed[2] * delta;
      let screenX = coin.screenX;
      let screenY = coin.screenY - (1 - entryEase) * 0.12;
      let scale = coin.scale * (0.68 + entryEase * 0.32);
      let opacity = 0.98 * entryEase;

      if (coin.collecting) {
        coin.collectionProgress = Math.min(1, coin.collectionProgress + delta * 2.4);
        const progress = 1 - (1 - coin.collectionProgress) ** 3;
        screenX += (counterPos.x - screenX) * progress;
        screenY += (counterPos.y - screenY) * progress;
        scale *= 1 - progress * 0.62;
        opacity *= 1 - progress * 0.55;
        if (coin.collectionProgress >= 1) coin.collecting = false;
      }

      const position = screenToWorld(screenX, screenY, coin.z);
      drawSprite(itemTextures.coin, { ...position, z: coin.z }, coin.rotation, scale * 1.5, opacity);
    }

    if (running) frameHandle = window.requestAnimationFrame(render);
  };

  const resize = (refreshLayout = true) => {
    if (refreshLayout) {
      const bounds = lootSection.getBoundingClientRect();
      if (!bounds.width || !bounds.height) return;
      sectionRect = {
        pageLeft: bounds.left + window.scrollX,
        pageTop: bounds.top + window.scrollY,
        width: bounds.width,
        height: bounds.height,
      };
      width = bounds.width;
      height = bounds.height;
      updateCounterPosition();
    }
    if (!width || !height) return;
    const mobile = width < 760;
    pixelRatio = Math.min(window.devicePixelRatio || 1, mobile ? 1 : 1.25);
    canvas.width = Math.round(width * pixelRatio);
    canvas.height = Math.round(height * pixelRatio);
    projection = createProjection(Math.PI / 3.8, width / height, 0.1, 50);

    const nextObjectCount = motionPreference.matches ? 0 : mobile ? 8 : 14;
    if (nextObjectCount !== objectCount) {
      objectCount = nextObjectCount;
      objectRainLayout = createRainLayout(objectCount);
      const kindCycle = ["weapon", "weapon", "crate", "equipment", "diamond", "weapon", "equipment"];
      const kinds = shuffle(Array.from({ length: objectCount }, (_, index) => kindCycle[index % kindCycle.length]));
      objects = Array.from({ length: objectCount }, (_, index) =>
        createLoot(kinds[index], index, objectRainLayout.positions[index]),
      );
    }
    stickerObjects = createStickerObjects();
    coinObjects = createCoinBatch();

    const nextParticleCount = motionPreference.matches ? 0 : mobile ? 22 : 52;
    if (nextParticleCount !== particleCount) {
      particleCount = nextParticleCount;
      const particles = new Float32Array(particleCount * 7);
      for (let index = 0; index < particleCount; index += 1) {
        const offset = index * 7;
        const depth = randomBetween(-7.5, -1.5);
        const verticalRange = Math.tan(Math.PI / 7.6) * (cameraZ - depth);
        const horizontalRange = verticalRange * (width / height);
        particles[offset] = randomBetween(-0.15, 1) * horizontalRange * 1.8;
        particles[offset + 1] = randomBetween(-1, 1) * verticalRange;
        particles[offset + 2] = depth;
        const color = Math.random() > 0.7 ? [1, 0.3, 0.68] : [0.48, 0.25, 0.78];
        particles.set(color, offset + 3);
        particles[offset + 6] = randomBetween(1.2, 3.1);
      }
      gl.bindBuffer(gl.ARRAY_BUFFER, particleBuffer);
      gl.bufferData(gl.ARRAY_BUFFER, particles, gl.STATIC_DRAW);
    }
    if (!running) render(performance.now());
  };
  const scheduleResize = () => {
    if (resizeFrame) return;
    resizeFrame = window.requestAnimationFrame(() => {
      resizeFrame = 0;
      resize();
    });
  };

  const pause = () => {
    if (!running) return;
    running = false;
    window.cancelAnimationFrame(frameHandle);
    frameHandle = 0;
    lastFrameTime = 0;
  };

  const play = () => {
    if (running || !visible || document.hidden || motionPreference.matches) return;
    running = true;
    frameHandle = window.requestAnimationFrame(render);
  };

  const handleContextLost = (event) => {
    event.preventDefault();
    pause();
    canvas.dataset.webgl = "error";
    console.error("The NEEN hero loot scene failed during rendering: the WebGL context was lost.");
  };

  let initializationStage = "WebGL context creation";
  try {
    initializationStage = "waiting for the loot section";
    if (!(await waitForLootSection())) return;

    initializationStage = "WebGL context creation";
    gl = canvas.getContext("webgl", {
      alpha: true,
      antialias: false,
      depth: true,
      powerPreference: "low-power",
      premultipliedAlpha: false,
    });
    if (!gl) {
      canvas.dataset.webgl = "unavailable";
      return;
    }

    initializationStage = "sprite asset loading and texture upload";
    const loadedTextures = Object.fromEntries(await Promise.all(
      Object.entries(spriteSources)
        .filter(([name]) => name !== "stickerSheet" && name !== "snoopStickers")
        .map(async ([name, source]) => [
          name,
          name === "coin"
            ? await loadLayeredCoinTexture(gl, source)
            : await loadSpriteTexture(gl, source, name),
        ]),
    ));
    let loadedStickers = {};
    try {
      loadedStickers = await loadStickerTextures(gl, spriteSources.stickerSheet, spriteSources.snoopStickers);
    } catch (error) {
      console.warn("The NEEN hero loot stickers could not be loaded; the original loot will continue.", error);
    }
    itemTextures = {
      coin: loadedTextures.coin,
      crate: loadedTextures.crate,
      backpack: loadedTextures.backpack,
    };
    weaponTextures = [loadedTextures.ak47, loadedTextures.mp40];
    stickerTextures = Object.values(loadedStickers);

    initializationStage = "object shader compilation and program linking";
    objectProgram = createProgram(gl, vertexShaderSource, fragmentShaderSource, "object");
    initializationStage = "particle shader compilation and program linking";
    particleProgram = createProgram(gl, particleVertexShaderSource, particleFragmentShaderSource, "particle");
    initializationStage = "sprite shader compilation and program linking";
    spriteProgram = createProgram(gl, spriteVertexShaderSource, spriteFragmentShaderSource, "sprite");

    initializationStage = "object program uniform lookup";
    objectUniforms = {
      projection: getUniform(gl, objectProgram, "uProjection", "Object program"),
      position: getUniform(gl, objectProgram, "uPosition", "Object program"),
      rotation: getUniform(gl, objectProgram, "uRotation", "Object program"),
      scale: getUniform(gl, objectProgram, "uScale", "Object program"),
      opacity: getUniform(gl, objectProgram, "uOpacity", "Object program"),
      cameraZ: getUniform(gl, objectProgram, "uCameraZ", "Object program"),
    };

    initializationStage = "particle program uniform lookup";
    particleUniforms = {
      projection: getUniform(gl, particleProgram, "uProjection", "Particle program"),
      time: getUniform(gl, particleProgram, "uTime", "Particle program"),
      pixelRatio: getUniform(gl, particleProgram, "uPixelRatio", "Particle program"),
      cameraZ: getUniform(gl, particleProgram, "uCameraZ", "Particle program"),
    };

    initializationStage = "object program attribute lookup";
    objectAttributes = {
      position: getAttribute(gl, objectProgram, "aPosition", "Object program"),
      normal: getAttribute(gl, objectProgram, "aNormal", "Object program"),
      color: getAttribute(gl, objectProgram, "aColor", "Object program"),
    };

    initializationStage = "particle program attribute lookup";
    particleAttributes = {
      position: getAttribute(gl, particleProgram, "aPosition", "Particle program"),
      color: getAttribute(gl, particleProgram, "aColor", "Particle program"),
      size: getAttribute(gl, particleProgram, "aSize", "Particle program"),
    };

    initializationStage = "sprite program uniform lookup";
    spriteUniforms = {
      projection: getUniform(gl, spriteProgram, "uProjection", "Sprite program"),
      position: getUniform(gl, spriteProgram, "uPosition", "Sprite program"),
      rotation: getUniform(gl, spriteProgram, "uRotation", "Sprite program"),
      scale: getUniform(gl, spriteProgram, "uScale", "Sprite program"),
      cameraZ: getUniform(gl, spriteProgram, "uCameraZ", "Sprite program"),
      opacity: getUniform(gl, spriteProgram, "uOpacity", "Sprite program"),
      preserveColor: getUniform(gl, spriteProgram, "uPreserveColor", "Sprite program"),
      texelSize: getUniform(gl, spriteProgram, "uTexelSize", "Sprite program"),
      texture: getUniform(gl, spriteProgram, "uTexture", "Sprite program"),
    };

    initializationStage = "sprite program attribute lookup";
    spriteAttributes = {
      position: getAttribute(gl, spriteProgram, "aPosition", "Sprite program"),
      texCoord: getAttribute(gl, spriteProgram, "aTexCoord", "Sprite program"),
    };

    initializationStage = "diamond geometry buffer creation";
    diamondBuffer = createBuffer(gl, diamondGeometry(), "diamond geometry");
    initializationStage = "image sprite geometry buffer creation";
    spriteBuffer = createBuffer(gl, spriteGeometry(), "sprite");
    initializationStage = "particle buffer creation";
    particleBuffer = createBuffer(gl, new Float32Array(0), "particle");
    initializationStage = "collection spark buffer creation";
    sparkBuffer = createBuffer(gl, collectionEffectVertices, "collection spark", gl.DYNAMIC_DRAW);
    gl.clearColor(0, 0, 0, 0);

    initializationStage = "initial canvas resize and scene population";
    resize();
    canvas.dataset.webgl = "ready";

    initializationStage = "render loop and event setup";
    canvas.addEventListener("webglcontextlost", handleContextLost, false);
    canvas.addEventListener("pointerdown", handleCanvasPointerDown);
    canvas.addEventListener("keydown", handleCanvasKeyDown);
    syncCollectionUi();
    updateBatchClock();

    let observer = null;
    if ("IntersectionObserver" in window) {
      observer = new IntersectionObserver(
        ([entry]) => {
          visible = entry.isIntersecting;
          if (visible) play();
          else pause();
        },
        { rootMargin: "120px 0px", threshold: 0.01 },
      );
      observer.observe(lootSection);
    } else {
      visible = true;
    }

    const handleVisibility = () => {
      if (document.hidden) pause();
      else {
        updateBatchClock();
        play();
      }
    };
    const handlePointerMove = (event) => {
      if (motionPreference.matches || width < 760) return;
      pointerPageX = event.pageX;
      pointerPageY = event.pageY;
      pointerPositionPending = true;
    };
    const resetPointer = () => {
      pointerPositionPending = false;
      targetPointerX = 0;
      targetPointerY = 0;
    };
    const handleMotionChange = () => {
      resize(false);
      if (motionPreference.matches) {
        pause();
        render(performance.now());
      } else {
        play();
      }
    };

    window.addEventListener("resize", scheduleResize, { passive: true });
    document.addEventListener("visibilitychange", handleVisibility);
    lootSection.addEventListener("pointermove", handlePointerMove, { passive: true });
    lootSection.addEventListener("pointerleave", resetPointer, { passive: true });
    motionPreference.addEventListener("change", handleMotionChange);
    window.addEventListener(
      "pagehide",
      () => {
        pause();
        observer?.disconnect();
        window.removeEventListener("resize", scheduleResize);
        window.cancelAnimationFrame(resizeFrame);
        resizeFrame = 0;
        document.removeEventListener("visibilitychange", handleVisibility);
        window.clearTimeout(batchTimer);
        lootSection.removeEventListener("pointermove", handlePointerMove);
        lootSection.removeEventListener("pointerleave", resetPointer);
        canvas.removeEventListener("pointerdown", handleCanvasPointerDown);
        canvas.removeEventListener("keydown", handleCanvasKeyDown);
        motionPreference.removeEventListener("change", handleMotionChange);
        canvas.removeEventListener("webglcontextlost", handleContextLost);
        [
          diamondBuffer,
          spriteBuffer,
          particleBuffer,
          sparkBuffer,
        ]
          .forEach((buffer) => gl.deleteBuffer(buffer));
        [...Object.values(itemTextures), ...weaponTextures, ...stickerTextures]
          .forEach(({ texture }) => gl.deleteTexture(texture));
        gl.deleteProgram(objectProgram);
        gl.deleteProgram(particleProgram);
        gl.deleteProgram(spriteProgram);
      },
      { once: true },
    );

    if (!observer) {
      visible = true;
      play();
    }
  } catch (error) {
    pause();
    console.error(`The NEEN hero loot scene failed during ${initializationStage}.`, error);
    canvas.dataset.webgl = "error";
  }
})();
