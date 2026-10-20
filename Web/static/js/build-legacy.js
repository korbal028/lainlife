// ES5-копии скриптов для старых браузеров (IE11, Edge на Windows Phone): скрипты движка -> js/legacy,
// res/*.js тем с legacy_js: true в theme.yml -> res/legacy, нужные им полифилы -> js/legacy/polyfills.js.
// Запускается после npm install, вручную - npm run build-legacy (после обновления темы тоже).
"use strict";

const fs    = require("fs");
const path  = require("path");
const babel = require("@babel/core");
const buildCoreJs = require("core-js-builder");
const terser = require("terser");

const JS_DIR     = __dirname;
const ROOT       = path.resolve(JS_DIR, "../../..");
const TARGETS    = "ie 11";
const CORE_JS    = require("core-js/package.json").version;
const CORE_JS_RE = /^require\("core-js\/modules\/([\w.-]+)\.js"\);\n/gm;

// на случай кода, который babel не видит: инлайн-скрипты шаблонов и data-bind
const BASE_POLYFILLS = [
    "es.symbol", "es.symbol.iterator", "es.promise", "es.promise.finally", "es.object.assign",
    "es.object.entries", "es.object.values", "es.array.from", "es.array.find", "es.array.find-index",
    "es.array.includes", "es.array.iterator", "es.string.includes", "es.string.starts-with",
    "es.string.ends-with", "es.string.pad-start", "es.string.trim", "es.map", "es.set", "es.weak-map",
    "web.url", "web.url-search-params", "web.dom-collections.for-each", "web.dom-collections.iterator",
];

// подключаются после core-js: fetch нужен Promise, AbortController патчит fetch
const EXTRA_POLYFILLS = [
    path.join(JS_DIR, "legacy-src/dom-polyfills.js"),
    require.resolve("whatwg-fetch/dist/fetch.umd.js"),
    require.resolve("abortcontroller-polyfill/dist/polyfill-patch-fetch.js"),
    require.resolve("intersection-observer/intersection-observer.js"),
];

const usedPolyfills = new Set(BASE_POLYFILLS);

const transpile = (src, dest) => {
    // sourceType script: без "use strict" и import, верхнеуровневые объявления остаются глобальными
    const { code } = babel.transformSync(fs.readFileSync(src, "utf8"), {
        babelrc: false,
        configFile: false,
        filename: src,
        sourceType: "script",
        presets: [["@babel/preset-env", { targets: TARGETS, useBuiltIns: "usage", corejs: CORE_JS, bugfixes: true }]],
    });

    const out = code.replace(CORE_JS_RE, (_, mod) => {
        usedPolyfills.add(mod);
        return "";
    });

    fs.mkdirSync(path.dirname(dest), { recursive: true });
    fs.writeFileSync(dest, out);
};

const listJs = (dir, skip) => fs.readdirSync(dir, { withFileTypes: true }).flatMap(entry => {
    const full = path.join(dir, entry.name);
    if(skip(full)) return [];
    if(entry.isDirectory()) return listJs(full, skip);

    return entry.name.endsWith(".js") && !entry.name.endsWith(".min.js") ? [full] : [];
});

const jobs = [];

const engineOut = path.join(JS_DIR, "legacy");
fs.readdirSync(JS_DIR)
    .filter(name => name.endsWith(".js") && name !== path.basename(__filename))
    .forEach(name => jobs.push([path.join(JS_DIR, name), path.join(engineOut, name)]));

const themesDir = path.join(ROOT, "themepacks");
if(fs.existsSync(themesDir)) {
    fs.readdirSync(themesDir, { withFileTypes: true }).filter(e => e.isDirectory()).forEach(theme => {
        const home     = path.join(themesDir, theme.name);
        const manifest = path.join(home, "theme.yml");
        const resDir   = path.join(home, "res");
        if(!fs.existsSync(manifest) || !fs.existsSync(resDir)) return;
        if(!/^legacy_js:\s*(true|yes|1)\s*$/m.test(fs.readFileSync(manifest, "utf8"))) return;

        const resOut = path.join(resDir, "legacy");
        fs.rmSync(resOut, { recursive: true, force: true });
        listJs(resDir, full => full === resOut).forEach(src => jobs.push([src, path.join(resOut, path.relative(resDir, src))]));
    });
}

(async () => {
    fs.rmSync(engineOut, { recursive: true, force: true });

    let failed = 0;
    for(const [src, dest] of jobs) {
        try {
            transpile(src, dest);
        } catch(e) {
            failed++;
            console.error(`build-legacy: ${path.relative(ROOT, src)}: ${e.message}`);
        }
    }

    const coreJs = await buildCoreJs({ modules: [...usedPolyfills], targets: TARGETS, format: "bundle", summary: {} });
    const extra  = EXTRA_POLYFILLS.map(file => fs.readFileSync(file, "utf8"));
    const { code: polyfills } = await terser.minify([coreJs, ...extra].join("\n;\n"), { ecma: 5 });
    fs.mkdirSync(engineOut, { recursive: true });
    fs.writeFileSync(path.join(engineOut, "polyfills.js"), polyfills);

    console.log(`build-legacy: ${jobs.length - failed} files, ${usedPolyfills.size} core-js modules${failed ? `, ${failed} failed` : ""}`);
    process.exitCode = failed ? 1 : 0;
})();
