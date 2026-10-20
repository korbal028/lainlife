// DOM-полифилы для IE11 и Edge на Windows Phone, которых нет в core-js; только ES5
(function () {
    var EP = Element.prototype;

    var define = function (protos, name, value) {
        protos.forEach(function (proto) {
            if (proto && !(name in proto))
                Object.defineProperty(proto, name, { configurable: true, writable: true, value: value });
        });
    };

    if (!EP.matches)
        EP.matches = EP.msMatchesSelector || EP.webkitMatchesSelector;

    define([EP], "closest", function (selector) {
        var el = this;
        while (el && el.nodeType === 1) {
            if (el.matches(selector))
                return el;

            el = el.parentElement || el.parentNode;
        }

        return null;
    });

    // аргументы append/before/... - узлы или строки
    var toNode = function (args) {
        var frag = document.createDocumentFragment();
        for (var i = 0; i < args.length; i++)
            frag.appendChild(args[i] instanceof Node ? args[i] : document.createTextNode(String(args[i])));

        return frag;
    };

    var childNodes = [EP, CharacterData.prototype, window.DocumentType && DocumentType.prototype];
    define(childNodes, "remove", function () {
        if (this.parentNode) this.parentNode.removeChild(this);
    });
    define(childNodes, "before", function () {
        if (this.parentNode) this.parentNode.insertBefore(toNode(arguments), this);
    });
    define(childNodes, "after", function () {
        if (this.parentNode) this.parentNode.insertBefore(toNode(arguments), this.nextSibling);
    });
    define(childNodes, "replaceWith", function () {
        if (this.parentNode) this.parentNode.replaceChild(toNode(arguments), this);
    });

    var parentNodes = [EP, Document.prototype, DocumentFragment.prototype];
    define(parentNodes, "append", function () {
        this.appendChild(toNode(arguments));
    });
    define(parentNodes, "prepend", function () {
        this.insertBefore(toNode(arguments), this.firstChild);
    });

    if (!("isConnected" in Node.prototype))
        Object.defineProperty(Node.prototype, "isConnected", {
            configurable: true,
            get: function () {
                var el = this.nodeType === 1 ? this : this.parentNode;
                return this === document || (!!el && el.nodeType === 1 && document.documentElement.contains(el));
            },
        });

    define([EP], "scrollTo", function (x, y) {
        if (x && typeof x === "object") {
            if (x.left != null) this.scrollLeft = x.left;
            if (x.top != null) this.scrollTop = x.top;
        } else {
            this.scrollLeft = x;
            this.scrollTop  = y;
        }
    });

    if (!("scrollBehavior" in document.documentElement.style)) {
        var winScrollTo = window.scrollTo;
        window.scrollTo = function (x, y) {
            if (x && typeof x === "object")
                return winScrollTo.call(window, x.left != null ? x.left : window.pageXOffset, x.top != null ? x.top : window.pageYOffset);

            return winScrollTo.call(window, x, y);
        };
    }

    try {
        if (!("scrollY" in window)) {
            Object.defineProperty(window, "scrollY", { configurable: true, get: function () { return window.pageYOffset; } });
            Object.defineProperty(window, "scrollX", { configurable: true, get: function () { return window.pageXOffset; } });
        }
    } catch (e) {}

    // IE11 не понимает несколько классов в add/remove и второй аргумент toggle
    var probe = document.createElement("div");
    probe.classList.add("a", "b");
    if (!probe.classList.contains("b")) {
        var tokenAdd = DOMTokenList.prototype.add, tokenRemove = DOMTokenList.prototype.remove;
        DOMTokenList.prototype.add = function () {
            for (var i = 0; i < arguments.length; i++) tokenAdd.call(this, arguments[i]);
        };
        DOMTokenList.prototype.remove = function () {
            for (var i = 0; i < arguments.length; i++) tokenRemove.call(this, arguments[i]);
        };
    }
    if (!probe.classList.toggle("a", true)) {
        var tokenToggle = DOMTokenList.prototype.toggle;
        DOMTokenList.prototype.toggle = function (token, force) {
            if (force === undefined)
                return tokenToggle.call(this, token);

            force ? this.add(token) : this.remove(token);
            return !!force;
        };
    }

    if (typeof window.CustomEvent !== "function") {
        var CustomEventShim = function (type, params) {
            params = params || {};
            var e = document.createEvent("CustomEvent");
            e.initCustomEvent(type, !!params.bubbles, !!params.cancelable, params.detail);
            return e;
        };
        CustomEventShim.prototype = window.Event.prototype;
        window.CustomEvent = CustomEventShim;
    }

    if (typeof window.Event !== "function") {
        var EventShim = function (type, params) {
            params = params || {};
            var e = document.createEvent("Event");
            e.initEvent(type, !!params.bubbles, !!params.cancelable);
            return e;
        };
        EventShim.prototype = window.Event.prototype;
        window.Event = EventShim;
    }

    // { once, passive, capture } третьим аргументом: старые браузеры считают объект за capture = true
    var optionsSupported = false;
    try {
        var opts = Object.defineProperty({}, "once", { get: function () { optionsSupported = true; } });
        window.addEventListener("test", null, opts);
        window.removeEventListener("test", null, opts);
    } catch (e) {}

    if (!optionsSupported) {
        var patched = [];
        var patchOwner = function (obj) {
            while (obj && !Object.prototype.hasOwnProperty.call(obj, "addEventListener"))
                obj = Object.getPrototypeOf(obj);
            if (!obj || patched.indexOf(obj) !== -1) return;
            patched.push(obj);

            var add = obj.addEventListener, remove = obj.removeEventListener;
            obj.addEventListener = function (type, listener, options) {
                if (!options || typeof options !== "object")
                    return add.call(this, type, listener, options);

                var capture = !!options.capture;
                if (options.once && listener) {
                    var self = this, original = listener;
                    listener = function (e) {
                        remove.call(self, type, listener, capture);
                        return typeof original === "function" ? original.call(self, e) : original.handleEvent(e);
                    };
                }

                return add.call(this, type, listener, capture);
            };
            obj.removeEventListener = function (type, listener, options) {
                return remove.call(this, type, listener, options && typeof options === "object" ? !!options.capture : options);
            };
        };

        [window, document, document.documentElement, document.createTextNode(""), new XMLHttpRequest()].forEach(patchOwner);
    }

    if (window.FormData && !FormData.prototype.set)
        FormData.prototype.set = function (name, value, filename) {
            return filename === undefined ? this.append(name, value) : this.append(name, value, filename);
        };

    // режимы select/start/end; без режима курсор ставится после вставки
    [window.HTMLInputElement, window.HTMLTextAreaElement].forEach(function (Ctor) {
        if (!Ctor || Ctor.prototype.setRangeText) return;

        Ctor.prototype.setRangeText = function (text, start, end, mode) {
            if (start === undefined) {
                start = this.selectionStart;
                end   = this.selectionEnd;
            }

            this.value = this.value.slice(0, start) + text + this.value.slice(end);
            if (mode === "select") {
                this.selectionStart = start;
                this.selectionEnd   = start + text.length;
            } else {
                this.selectionStart = this.selectionEnd = mode === "start" ? start : start + text.length;
            }
        };
    });
})();
