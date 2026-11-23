/******/ (() => { // webpackBootstrap
/******/ 	"use strict";
/******/ 	var __webpack_modules__ = ({

/***/ "./assets/src/js/settings-page/components/custom-tab-page.js":
/*!*******************************************************************!*\
  !*** ./assets/src/js/settings-page/components/custom-tab-page.js ***!
  \*******************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* binding */ CustomTabPage)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _header_save_button__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ../header-save-button */ "./assets/src/js/settings-page/header-save-button.js");
/**
 * Renders a tab registered with acf.settingsPage.registerTab() inside SCF's page.
 *
 * @since SCF 6.9.10
 */




/**
 * Mounts a registered tab into its container, and unmounts it when the tab is hidden.
 *
 * @param {Object} props        Props.
 * @param {Object} props.tab    The registered tab definition.
 * @param {Object} props.config The settings page config for this tab.
 */
function CustomTabPage({
  tab,
  config
}) {
  const containerRef = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useRef)(null);
  // Read the latest config when mounting without remounting on every config change.
  const configRef = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useRef)(config);
  configRef.current = config;

  // The tab renders its own UI, so it saves with its own controls.
  (0,_header_save_button__WEBPACK_IMPORTED_MODULE_1__.useHeaderSaveButton)({
    visible: false,
    disabled: true
  });
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    const container = containerRef.current;
    if (!container) {
      return;
    }
    try {
      tab.mount(container, configRef.current);
    } catch (error) {
      // eslint-disable-next-line no-console
      console.error(error);
    }
    return () => {
      try {
        tab.unmount?.(container);
      } catch (error) {
        // eslint-disable-next-line no-console
        console.error(error);
      }
      container.replaceChildren();
    };
  }, [tab]);
  return /*#__PURE__*/React.createElement("div", {
    ref: containerRef,
    className: "scf-settings-custom-tab",
    "data-acf-settings-tab": config.activeTab
  });
}

/***/ }),

/***/ "./assets/src/js/settings-page/components/field-row.js":
/*!*************************************************************!*\
  !*** ./assets/src/js/settings-page/components/field-row.js ***!
  \*************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* binding */ FieldRow)
/* harmony export */ });
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/components */ "@wordpress/components");
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_components__WEBPACK_IMPORTED_MODULE_0__);
/**
 * A settings field row: label column and control column.
 *
 * @since SCF 6.9.10
 */


function Badge({
  children,
  tone = 'default'
}) {
  return /*#__PURE__*/React.createElement("span", {
    className: `scf-settings-badge is-${tone}`
  }, children);
}
function ManagedBadge({
  strings
}) {
  const badge = /*#__PURE__*/React.createElement(Badge, null, strings.managedViaCode);
  if (!strings.managedViaCodeTooltip) {
    return badge;
  }
  return /*#__PURE__*/React.createElement(_wordpress_components__WEBPACK_IMPORTED_MODULE_0__.Tooltip, {
    text: strings.managedViaCodeTooltip
  }, /*#__PURE__*/React.createElement("span", {
    className: "scf-settings-badge-trigger",
    tabIndex: 0
  }, badge));
}
function LearnMoreLink({
  url,
  label,
  strings
}) {
  const ariaLabel = strings.learnMoreAbout ? `${strings.learnMoreAbout.replace('%s', label)} ${strings.opensInNewTab || ''}`.trim() : undefined;
  return /*#__PURE__*/React.createElement(_wordpress_components__WEBPACK_IMPORTED_MODULE_0__.ExternalLink, {
    href: url,
    "aria-label": ariaLabel
  }, strings.learnMore);
}
function FieldControl({
  field,
  value,
  disabled,
  onChange
}) {
  if (field.type === 'toggle') {
    return /*#__PURE__*/React.createElement(_wordpress_components__WEBPACK_IMPORTED_MODULE_0__.ToggleControl, {
      __nextHasNoMarginBottom: true,
      label: /*#__PURE__*/React.createElement("span", {
        className: "screen-reader-text"
      }, field.label),
      checked: !!value,
      disabled: disabled,
      onChange: onChange
    });
  }
  if (field.type === 'select') {
    return /*#__PURE__*/React.createElement(_wordpress_components__WEBPACK_IMPORTED_MODULE_0__.SelectControl, {
      __nextHasNoMarginBottom: true,
      __next40pxDefaultSize: true,
      hideLabelFromVision: true,
      label: field.label,
      value: value === null || value === undefined ? '' : value,
      options: field.options || [],
      disabled: disabled,
      onChange: onChange
    });
  }
  return /*#__PURE__*/React.createElement(_wordpress_components__WEBPACK_IMPORTED_MODULE_0__.TextControl, {
    __nextHasNoMarginBottom: true,
    __next40pxDefaultSize: true,
    hideLabelFromVision: true,
    label: field.label,
    value: value === null || value === undefined ? '' : value,
    disabled: disabled,
    onChange: onChange
  });
}

/**
 * Renders one field from the 'acf/settings_page/general/{section}/fields' filter as a row.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.field    The field.
 * @param {*}        props.value    The current value.
 * @param {Object}   props.strings  Translated strings.
 * @param {Function} props.onChange Called with the new value.
 */
function FieldRow({
  field,
  value,
  strings,
  onChange
}) {
  const isToggle = field.type === 'toggle';
  const disabled = !!(field.managed || field.disabled);
  return /*#__PURE__*/React.createElement("div", {
    className: "scf-settings-row",
    "data-setting": field.key
  }, /*#__PURE__*/React.createElement("div", {
    className: "scf-settings-row__label"
  }, /*#__PURE__*/React.createElement("span", {
    className: "scf-settings-row__title"
  }, field.label), field.experimental && /*#__PURE__*/React.createElement(Badge, {
    tone: "warning"
  }, strings.experimental)), /*#__PURE__*/React.createElement("div", {
    className: `scf-settings-row__content ${isToggle ? 'is-toggle' : 'is-input'}`
  }, /*#__PURE__*/React.createElement("div", {
    className: "scf-settings-row__control"
  }, /*#__PURE__*/React.createElement(FieldControl, {
    field: field,
    value: value,
    disabled: disabled,
    onChange: onChange
  })), /*#__PURE__*/React.createElement("div", {
    className: "scf-settings-row__help"
  }, field.description && /*#__PURE__*/React.createElement("span", {
    className: "scf-settings-row__description"
  }, field.description, ' '), field.learnMoreUrl && /*#__PURE__*/React.createElement(LearnMoreLink, {
    url: field.learnMoreUrl,
    label: field.label,
    strings: strings
  }), field.managed && /*#__PURE__*/React.createElement("div", {
    className: "scf-settings-row__badges"
  }, /*#__PURE__*/React.createElement(ManagedBadge, {
    strings: strings
  })))));
}

/***/ }),

/***/ "./assets/src/js/settings-page/components/settings-page.js":
/*!*****************************************************************!*\
  !*** ./assets/src/js/settings-page/components/settings-page.js ***!
  \*****************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* binding */ SettingsPage)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _field_row__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./field-row */ "./assets/src/js/settings-page/components/field-row.js");
/* harmony import */ var _header_save_button__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ../header-save-button */ "./assets/src/js/settings-page/header-save-button.js");
/* harmony import */ var _utils__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ../utils */ "./assets/src/js/settings-page/utils.js");
/**
 * The default tab UI: sections of fields, saved with the header Save button.
 *
 * @since SCF 6.9.10
 */






/**
 * Renders a tab's sections and saves its changed settings.
 *
 * @param {Object}   props                        Props.
 * @param {Object}   props.config                 The settings page config for this tab.
 * @param {Function} props.addNotice              Shows a snackbar notice.
 * @param {Function} props.onUnsavedChangesChange Called when the unsaved changes state changes.
 */
function SettingsPage({
  config,
  addNotice,
  onUnsavedChangesChange
}) {
  const strings = config.strings || {};
  const sections = (0,_utils__WEBPACK_IMPORTED_MODULE_3__.getSections)(config.tabData);
  const [values, setValues] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(() => (0,_utils__WEBPACK_IMPORTED_MODULE_3__.getInitialSettings)(sections));
  const [saved, setSaved] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(() => (0,_utils__WEBPACK_IMPORTED_MODULE_3__.getInitialSettings)(sections));
  const [isSaving, setIsSaving] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const valuesRef = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useRef)(values);
  valuesRef.current = values;
  const changed = (0,_utils__WEBPACK_IMPORTED_MODULE_3__.getChangedSettings)(sections, values, saved);
  const isDirty = Object.keys(changed).length > 0;

  // The app owns navigation, so report unsaved changes up for it to guard tab switches and page unloads.
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    onUnsavedChangesChange?.(isDirty);
  }, [isDirty, onUnsavedChangesChange]);
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => () => onUnsavedChangesChange?.(false), [onUnsavedChangesChange]);
  const save = async () => {
    const sent = (0,_utils__WEBPACK_IMPORTED_MODULE_3__.getChangedSettings)(sections, valuesRef.current, saved);
    if (isSaving || Object.keys(sent).length === 0) {
      return;
    }
    setIsSaving(true);
    try {
      const data = await (0,_utils__WEBPACK_IMPORTED_MODULE_3__.saveSettings)(config, sent);
      const result = (0,_utils__WEBPACK_IMPORTED_MODULE_3__.applyConfirmedSettings)(sections, valuesRef.current, sent, data.settings);
      setSaved(prev => ({
        ...prev,
        ...result.saved
      }));
      setValues(prev => {
        const next = {
          ...prev
        };
        Object.keys(result.saved).forEach(key => {
          next[key] = result.values[key];
        });
        return next;
      });

      // Some settings may have been skipped or failed to save while others saved. They stay unsaved, so say which.
      const notSaved = [...(Array.isArray(data.skipped) ? data.skipped : []), ...(Array.isArray(data.failed) ? data.failed : [])];
      if (notSaved.length > 0) {
        addNotice(strings.settingsNotSaved ? strings.settingsNotSaved.replace('%s', notSaved.join(', ')) : strings.saveErrorMessage, 'error');
      } else {
        addNotice(strings.saveSuccessMessage);
      }
    } catch (error) {
      addNotice(error.message || strings.saveErrorMessage, 'error');
    } finally {
      setIsSaving(false);
    }
  };
  (0,_header_save_button__WEBPACK_IMPORTED_MODULE_2__.useHeaderSaveButton)({
    visible: sections.length > 0,
    disabled: !isDirty || isSaving,
    busy: isSaving,
    label: isSaving ? strings.saving : strings.save,
    onClick: save
  });
  if (sections.length === 0) {
    return null;
  }
  return /*#__PURE__*/React.createElement("form", {
    className: "scf-settings-form",
    onSubmit: event => {
      event.preventDefault();
      save();
    }
  }, sections.map((section, index) => /*#__PURE__*/React.createElement("section", {
    key: `${index}-${section.title}`,
    className: "scf-settings-section"
  }, section.title && /*#__PURE__*/React.createElement("div", {
    className: "scf-settings-section__header"
  }, /*#__PURE__*/React.createElement("h2", {
    className: "scf-settings-section__title"
  }, section.title)), section.fields.map(field => /*#__PURE__*/React.createElement(_field_row__WEBPACK_IMPORTED_MODULE_1__["default"], {
    key: field.key,
    field: field,
    value: values[field.key],
    strings: strings,
    onChange: next => setValues(prev => ({
      ...prev,
      [field.key]: next
    }))
  })))));
}

/***/ }),

/***/ "./assets/src/js/settings-page/header-save-button.js":
/*!***********************************************************!*\
  !*** ./assets/src/js/settings-page/header-save-button.js ***!
  \***********************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   SAVE_BUTTON_ID: () => (/* binding */ SAVE_BUTTON_ID),
/* harmony export */   useHeaderSaveButton: () => (/* binding */ useHeaderSaveButton)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/**
 * Connects the Save button in SCF's PHP-rendered header bar to the settings app.
 *
 * @since SCF 6.9.10
 */


const SAVE_BUTTON_ID = 'acf-settings-save';

/**
 * Keeps the header Save button's visibility, disabled and busy state in sync, and calls onClick when pressed.
 *
 * @param {Object}   options          Options.
 * @param {boolean}  options.visible  Whether the button is shown.
 * @param {boolean}  options.disabled Whether the button is disabled.
 * @param {boolean}  options.busy     Whether a save is in progress.
 * @param {string}   options.label    The button label.
 * @param {Function} options.onClick  Called when the button is pressed.
 */
function useHeaderSaveButton({
  visible,
  disabled,
  busy,
  label,
  onClick
}) {
  const onClickRef = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useRef)(onClick);
  onClickRef.current = onClick;
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    const button = document.getElementById(SAVE_BUTTON_ID);
    if (!button) {
      return;
    }
    const handleClick = event => {
      event.preventDefault();
      onClickRef.current?.();
    };
    button.addEventListener('click', handleClick);
    return () => button.removeEventListener('click', handleClick);
  }, []);
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    const button = document.getElementById(SAVE_BUTTON_ID);
    if (!button) {
      return;
    }
    button.hidden = !visible;
    button.disabled = !!disabled;
    button.setAttribute('aria-busy', busy ? 'true' : 'false');
    if (label) {
      button.textContent = label;
    }
  }, [visible, disabled, busy, label]);
}

/***/ }),

/***/ "./assets/src/js/settings-page/settings-app.js":
/*!*****************************************************!*\
  !*** ./assets/src/js/settings-page/settings-app.js ***!
  \*****************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* binding */ SettingsApp)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/components */ "@wordpress/components");
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _components_settings_page__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ./components/settings-page */ "./assets/src/js/settings-page/components/settings-page.js");
/* harmony import */ var _components_custom_tab_page__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ./components/custom-tab-page */ "./assets/src/js/settings-page/components/custom-tab-page.js");
/* harmony import */ var _settings_tab_registry__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ./settings-tab-registry */ "./assets/src/js/settings-page/settings-tab-registry.js");
/* harmony import */ var _utils__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! ./utils */ "./assets/src/js/settings-page/utils.js");
/**
 * The settings page app: tab bar, the active tab and snackbar notices.
 *
 * Tabs switch in-page: the app loads the tab's data with the "acf_settings_tab_data" AJAX action and updates
 * the URL, falling back to a full page load if that fails.
 *
 * @since SCF 6.9.10
 */








/**
 * Loads a tab's data.
 *
 * @param {Object} config The current config.
 * @param {string} tabKey The tab to load.
 * @return {Promise<Object|null>} The tab's config changes, or null if it couldn't be loaded.
 */
async function fetchTabData(config, tabKey) {
  const {
    payload
  } = await (0,_utils__WEBPACK_IMPORTED_MODULE_5__.postAjax)(config.ajaxUrl, {
    action: 'acf_settings_tab_data',
    nonce: config.nonce,
    tab: tabKey
  });
  return payload?.success && payload.data ? payload.data : null;
}

/**
 * Renders the settings app.
 *
 * @param {Object} props               Props.
 * @param {Object} props.initialConfig The localized acfSettingsPage data.
 */
function SettingsApp({
  initialConfig
}) {
  const [config, setConfig] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(initialConfig);
  const [notices, setNotices] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)([]);
  // Puts the tab bar back on the active tab after a cancelled or history-driven switch.
  const [tabBarSync, syncTabBar] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useReducer)(count => count + 1, 0);
  const configRef = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useRef)(config);
  configRef.current = config;
  // Incremented on every tab switch so a slow response for an earlier switch can't overwrite a later one.
  const switchRequestRef = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useRef)(0);
  const hasUnsavedChangesRef = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useRef)(false);
  const tabBarRef = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useRef)(null);
  const strings = config.strings || {};
  const tabs = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useMemo)(() => Array.isArray(config.tabs) ? config.tabs : [], [config.tabs]);
  const addNotice = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useCallback)((content, status = 'success') => {
    setNotices(current => [...current, {
      id: `${Date.now()}-${Math.random()}`,
      content,
      status
    }]);
  }, []);
  const onUnsavedChangesChange = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useCallback)(hasUnsavedChanges => {
    hasUnsavedChangesRef.current = hasUnsavedChanges;
  }, []);

  // Returns true if there are no unsaved changes, or the user confirms discarding them.
  const confirmDiscardChanges = () => {
    if (!hasUnsavedChangesRef.current) {
      return true;
    }
    // A native confirm matches the browser's own beforeunload prompt for a full page load.
    const message = configRef.current.strings?.unsavedChanges ?? '';
    // eslint-disable-next-line no-alert
    if (!window.confirm(message)) {
      return false;
    }
    // Clear the flag so the beforeunload guard doesn't prompt again if we fall back to a full page load.
    hasUnsavedChangesRef.current = false;
    return true;
  };
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    const handleBeforeUnload = event => {
      if (hasUnsavedChangesRef.current) {
        event.preventDefault();
        event.returnValue = '';
      }
    };
    window.addEventListener('beforeunload', handleBeforeUnload);
    return () => window.removeEventListener('beforeunload', handleBeforeUnload);
  }, []);

  // The server renders the loaded tab's label at the start of the title, so swap it for the active tab's after an in-page switch.
  const titleLabelRef = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useRef)(tabs.find(tab => tab.key === config.activeTab)?.label ?? '');
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    const label = tabs.find(tab => tab.key === config.activeTab)?.label;
    if (!label || label === titleLabelRef.current) {
      return;
    }
    if (titleLabelRef.current && document.title.startsWith(titleLabelRef.current)) {
      document.title = label + document.title.slice(titleLabelRef.current.length);
      titleLabelRef.current = label;
    }
  }, [config.activeTab, tabs]);

  // The tab bar keeps its own selection, so select the active tab in it when they differ. Remounting it
  // instead would also remount the tab's content and lose its unsaved changes.
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    const index = tabs.findIndex(tab => tab.key === config.activeTab);
    // Only the app's own tab bar, not one a custom tab renders.
    const button = tabBarRef.current?.querySelectorAll(':scope > .scf-settings-tabs > .components-tab-panel__tabs > .components-tab-panel__tabs-item')[index];
    if (button && !button.classList.contains('is-active')) {
      // onSelectTab() ignores the active tab, so this only updates the tab bar.
      button.click();
    }
  }, [config.activeTab, tabs, tabBarSync]);
  const switchTab = async (tabKey, {
    pushState = true
  } = {}) => {
    const current = configRef.current;
    const targetTab = current.tabs.find(tab => tab.key === tabKey);
    if (!targetTab) {
      return;
    }
    const requestId = ++switchRequestRef.current;
    try {
      const data = await fetchTabData(current, tabKey);
      // A later tab switch has started, so let that one win.
      if (requestId !== switchRequestRef.current) {
        return;
      }
      if (data) {
        setConfig(prev => ({
          ...prev,
          ...data,
          activeTab: tabKey
        }));
        if (pushState) {
          window.history.pushState({
            acfTab: tabKey
          }, '', targetTab.url);
        }
        window.scrollTo(0, 0);
        return;
      }
    } catch {
      if (requestId !== switchRequestRef.current) {
        return;
      }
      // Fall through to a full page load.
    }
    if (pushState) {
      window.location.href = targetTab.url;
    } else {
      window.location.reload();
    }
  };
  const onSelectTab = tabKey => {
    const current = configRef.current;
    if (tabKey === current.activeTab) {
      return;
    }
    if (!confirmDiscardChanges()) {
      // Put the tab bar back on the active tab.
      syncTabBar();
      return;
    }
    switchTab(tabKey);
  };
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    const handlePopState = () => {
      const current = configRef.current;
      const targetPage = new window.URLSearchParams(window.location.search).get('page') ?? '';
      // Match on the page slug in each tab's URL so the slug format is only defined in PHP.
      const targetTab = current.tabs.find(tab => new window.URL(tab.url, window.location.href).searchParams.get('page') === targetPage);
      if (!targetTab) {
        return;
      }

      // Navigating back to the rendered tab (e.g. Back then Forward mid-switch) must still cancel a pending switch.
      if (targetTab.key === current.activeTab) {
        ++switchRequestRef.current;
        return;
      }

      // The browser has already changed the URL, so put it back if the user stays.
      if (!confirmDiscardChanges()) {
        const currentTab = current.tabs.find(tab => tab.key === current.activeTab);
        if (currentTab) {
          window.history.pushState({
            acfTab: currentTab.key
          }, '', currentTab.url);
        }
        return;
      }
      switchTab(targetTab.key, {
        pushState: false
      });
    };
    window.addEventListener('popstate', handlePopState);
    return () => window.removeEventListener('popstate', handlePopState);
  }, []);

  // Re-render when a tab script registers, in case it loads after the app has mounted.
  const [, onTabRegistered] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useReducer)(count => count + 1, 0);
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => (0,_settings_tab_registry__WEBPACK_IMPORTED_MODULE_4__.subscribe)(onTabRegistered), []);

  // Tabs registered with acf.settingsPage.registerTab() render their own UI inside SCF's layout.
  const customTab = (0,_settings_tab_registry__WEBPACK_IMPORTED_MODULE_4__.getTab)(config.activeTab);
  const content = customTab ? /*#__PURE__*/React.createElement(_components_custom_tab_page__WEBPACK_IMPORTED_MODULE_3__["default"], {
    key: config.activeTab,
    tab: customTab,
    config: config
  }) : /*#__PURE__*/React.createElement(_components_settings_page__WEBPACK_IMPORTED_MODULE_2__["default"], {
    key: config.activeTab,
    config: config,
    addNotice: addNotice,
    onUnsavedChangesChange: onUnsavedChangesChange
  });
  return /*#__PURE__*/React.createElement("div", {
    className: "scf-settings",
    ref: tabBarRef
  }, tabs.length > 0 ? /*#__PURE__*/React.createElement(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.TabPanel, {
    className: "scf-settings-tabs",
    "aria-label": strings.settingsTabs,
    tabs: tabs.map(tab => ({
      name: tab.key,
      title: tab.count ? /*#__PURE__*/React.createElement(React.Fragment, null, tab.label, /*#__PURE__*/React.createElement("span", {
        className: "scf-settings-tab-count"
      }, tab.count)) : tab.label
    })),
    initialTabName: config.activeTab,
    selectOnMove: false,
    onSelect: onSelectTab
  }, () => /*#__PURE__*/React.createElement("div", {
    className: "scf-settings-tab-content"
  }, content)) : /*#__PURE__*/React.createElement("div", {
    className: "scf-settings-tab-content"
  }, content), /*#__PURE__*/React.createElement(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.SnackbarList, {
    className: "scf-settings-snackbars",
    notices: notices,
    onRemove: id => setNotices(current => current.filter(notice => notice.id !== id))
  }));
}

/***/ }),

/***/ "./assets/src/js/settings-page/settings-tab-registry.js":
/*!**************************************************************!*\
  !*** ./assets/src/js/settings-page/settings-tab-registry.js ***!
  \**************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   exposeRegistry: () => (/* binding */ exposeRegistry),
/* harmony export */   getTab: () => (/* binding */ getTab),
/* harmony export */   registerTab: () => (/* binding */ registerTab),
/* harmony export */   subscribe: () => (/* binding */ subscribe)
/* harmony export */ });
/**
 * Registry for settings tabs that render their own UI inside the settings page.
 *
 * A tab registered with a 'js' URL in the 'acf/settings_page/tabs' filter has that script loaded after
 * SCF's settings app. The script calls acf.settingsPage.registerTab() with mount and unmount callbacks,
 * and SCF calls them when the tab is shown or hidden, keeping its own page header and tab bar.
 *
 * Definitions:
 * - mount( container, config ): renders the tab into the container. Called each time the tab is shown.
 *   Use the config passed here rather than the global acfSettingsPage: when switching tabs in-page,
 *   the global still holds the data for the tab the page was loaded on.
 * - unmount( container ): optional. Cleans up when the tab is hidden, e.g. stopping timers and removing
 *   document event listeners. SCF empties the container afterwards.
 *
 * @since SCF 6.9.10
 */

const tabs = new Map();
const listeners = new Set();

/**
 * Registers a tab's UI.
 *
 * @param {string} key        The tab key, as registered with the 'acf/settings_page/tabs' filter.
 * @param {Object} definition The tab definition, with mount() and optional unmount().
 */
function registerTab(key, definition) {
  if (typeof definition?.mount !== 'function') {
    // eslint-disable-next-line no-console
    console.error(`acf.settingsPage.registerTab: the "${key}" tab needs a mount() function.`);
    return;
  }
  tabs.set(key, definition);
  listeners.forEach(listener => listener());
}

/**
 * Returns a registered tab definition.
 *
 * @param {string} key The tab key.
 * @return {Object|undefined} The definition, if registered.
 */
function getTab(key) {
  return tabs.get(key);
}

/**
 * Calls the listener whenever a tab is registered. Returns a function that unsubscribes.
 *
 * @param {Function} listener Called after each registration.
 * @return {Function} Unsubscribes the listener.
 */
function subscribe(listener) {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}

/**
 * Exposes the registry as acf.settingsPage.registerTab() for tab scripts. SCF's acf.js (which assigns
 * window.acf) may load first, so extend the object rather than replacing it.
 *
 * @param {Object} win The window object.
 */
function exposeRegistry(win = window) {
  win.acf = win.acf || {};
  win.acf.settingsPage = {
    ...(win.acf.settingsPage || {}),
    registerTab
  };
}

/***/ }),

/***/ "./assets/src/js/settings-page/utils.js":
/*!**********************************************!*\
  !*** ./assets/src/js/settings-page/utils.js ***!
  \**********************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   applyConfirmedSettings: () => (/* binding */ applyConfirmedSettings),
/* harmony export */   getChangedSettings: () => (/* binding */ getChangedSettings),
/* harmony export */   getInitialSettings: () => (/* binding */ getInitialSettings),
/* harmony export */   getSections: () => (/* binding */ getSections),
/* harmony export */   isDifferent: () => (/* binding */ isDifferent),
/* harmony export */   isEditable: () => (/* binding */ isEditable),
/* harmony export */   postAjax: () => (/* binding */ postAjax),
/* harmony export */   saveSettings: () => (/* binding */ saveSettings),
/* harmony export */   toBoolean: () => (/* binding */ toBoolean)
/* harmony export */ });
/**
 * Settings page helpers.
 *
 * @since SCF 6.9.10
 */

/**
 * Returns the sections of a tab's data, or an empty array.
 *
 * @param {Object} tabData The tab data from the server.
 * @return {Array} The sections.
 */
function getSections(tabData) {
  return Array.isArray(tabData?.sections) ? tabData.sections : [];
}

/**
 * Returns the initial value of every field, keyed by setting.
 *
 * @param {Array} sections The tab's sections.
 * @return {Object} Values keyed by setting.
 */
function getInitialSettings(sections) {
  const settings = {};
  sections.forEach(section => section.fields.forEach(field => {
    settings[field.key] = field.value ?? null;
  }));
  return settings;
}

/**
 * Reads a toggle value the way wp_validate_boolean() does, which is how the page reads toggle values on load.
 * A sanitize callback other than wp_validate_boolean() can return e.g. "0", which Boolean() would treat as true.
 *
 * @param {*} value The value from the server.
 * @return {boolean} The boolean value.
 */
function toBoolean(value) {
  if (typeof value === 'string') {
    return value !== '' && value !== '0' && value.toLowerCase() !== 'false';
  }
  return Boolean(value);
}

/**
 * Whether two setting values differ.
 *
 * @param {*} a A value.
 * @param {*} b Another value.
 * @return {boolean} True if they differ.
 */
function isDifferent(a, b) {
  return JSON.stringify(a) !== JSON.stringify(b);
}

/**
 * Whether a field can be changed in the UI.
 *
 * @param {Object} field The field.
 * @return {boolean} True if it can be edited.
 */
function isEditable(field) {
  return !field.managed && !field.disabled;
}

/**
 * Returns the editable settings that differ from their saved values.
 *
 * Disabled and managed fields are never sent, so their stored values can't be overwritten.
 *
 * @param {Array}  sections The tab's sections.
 * @param {Object} values   The current values.
 * @param {Object} saved    The saved values.
 * @return {Object} Changed values keyed by setting.
 */
function getChangedSettings(sections, values, saved) {
  const changed = {};
  sections.forEach(section => section.fields.forEach(field => {
    if (isEditable(field) && isDifferent(values[field.key], saved[field.key])) {
      changed[field.key] = values[field.key];
    }
  }));
  return changed;
}

/**
 * Sends a request to admin-ajax.php and returns the parsed JSON payload, or null if it isn't JSON.
 *
 * @param {string} ajaxUrl The admin-ajax.php URL.
 * @param {Object} params  The request parameters.
 * @return {Promise<{response: Response, payload: Object|null}>} The response and payload.
 */
async function postAjax(ajaxUrl, params) {
  const response = await window.fetch(ajaxUrl, {
    method: 'POST',
    credentials: 'same-origin',
    body: new window.URLSearchParams(params)
  });
  const payload = await response.json().catch(() => null);
  return {
    response,
    payload
  };
}

/**
 * Saves settings with the settings page's AJAX action.
 *
 * Resolves with the server's response data: the saved values in 'settings', and any settings in 'skipped'
 * or 'failed'. Rejects with an Error carrying the server's message when the request fails.
 *
 * @param {Object} config   The settings page config.
 * @param {Object} settings The settings to save.
 * @return {Promise<Object>} The response data.
 */
async function saveSettings(config, settings) {
  const {
    strings = {}
  } = config;
  let result;
  try {
    result = await postAjax(config.ajaxUrl, {
      action: config.action,
      nonce: config.nonce,
      tab: config.activeTab,
      acfSettings: JSON.stringify(settings)
    });
  } catch {
    throw new Error(strings.saveErrorMessage);
  }
  const {
    response,
    payload
  } = result;
  if (!response.ok || !payload?.success) {
    throw new Error(typeof payload?.data === 'string' && payload.data ? payload.data : strings.saveErrorMessage);
  }
  return payload.data && typeof payload.data === 'object' ? payload.data : {};
}

/**
 * Applies the values the server confirmed it saved.
 *
 * Only settings the server confirmed are saved. Anything skipped, or edited while the request was pending,
 * stays unsaved, and a newer edit isn't overwritten by the older saved value.
 *
 * @param {Array}  sections  The tab's sections.
 * @param {Object} current   The current values.
 * @param {Object} sent      The values that were sent.
 * @param {Object} confirmed The values the server sent back.
 * @return {{values: Object, saved: Object}} The confirmed values, and the current values with them applied.
 */
function applyConfirmedSettings(sections, current, sent, confirmed) {
  const types = {};
  sections.forEach(section => section.fields.forEach(field => {
    types[field.key] = field.type;
  }));
  const saved = {};
  Object.entries(confirmed || {}).forEach(([key, value]) => {
    saved[key] = types[key] === 'toggle' ? toBoolean(value) : value;
  });
  const values = {
    ...current
  };
  Object.entries(saved).forEach(([key, value]) => {
    if (!isDifferent(current[key], sent[key])) {
      values[key] = value;
    }
  });
  return {
    values,
    saved
  };
}

/***/ }),

/***/ "@wordpress/components":
/*!************************************!*\
  !*** external ["wp","components"] ***!
  \************************************/
/***/ ((module) => {

module.exports = window["wp"]["components"];

/***/ }),

/***/ "@wordpress/element":
/*!*********************************!*\
  !*** external ["wp","element"] ***!
  \*********************************/
/***/ ((module) => {

module.exports = window["wp"]["element"];

/***/ })

/******/ 	});
/************************************************************************/
/******/ 	// The module cache
/******/ 	var __webpack_module_cache__ = {};
/******/ 	
/******/ 	// The require function
/******/ 	function __webpack_require__(moduleId) {
/******/ 		// Check if module is in cache
/******/ 		var cachedModule = __webpack_module_cache__[moduleId];
/******/ 		if (cachedModule !== undefined) {
/******/ 			return cachedModule.exports;
/******/ 		}
/******/ 		// Create a new module (and put it into the cache)
/******/ 		var module = __webpack_module_cache__[moduleId] = {
/******/ 			// no module.id needed
/******/ 			// no module.loaded needed
/******/ 			exports: {}
/******/ 		};
/******/ 	
/******/ 		// Execute the module function
/******/ 		__webpack_modules__[moduleId](module, module.exports, __webpack_require__);
/******/ 	
/******/ 		// Return the exports of the module
/******/ 		return module.exports;
/******/ 	}
/******/ 	
/************************************************************************/
/******/ 	/* webpack/runtime/compat get default export */
/******/ 	(() => {
/******/ 		// getDefaultExport function for compatibility with non-harmony modules
/******/ 		__webpack_require__.n = (module) => {
/******/ 			var getter = module && module.__esModule ?
/******/ 				() => (module['default']) :
/******/ 				() => (module);
/******/ 			__webpack_require__.d(getter, { a: getter });
/******/ 			return getter;
/******/ 		};
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/define property getters */
/******/ 	(() => {
/******/ 		// define getter functions for harmony exports
/******/ 		__webpack_require__.d = (exports, definition) => {
/******/ 			for(var key in definition) {
/******/ 				if(__webpack_require__.o(definition, key) && !__webpack_require__.o(exports, key)) {
/******/ 					Object.defineProperty(exports, key, { enumerable: true, get: definition[key] });
/******/ 				}
/******/ 			}
/******/ 		};
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/hasOwnProperty shorthand */
/******/ 	(() => {
/******/ 		__webpack_require__.o = (obj, prop) => (Object.prototype.hasOwnProperty.call(obj, prop))
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/make namespace object */
/******/ 	(() => {
/******/ 		// define __esModule on exports
/******/ 		__webpack_require__.r = (exports) => {
/******/ 			if(typeof Symbol !== 'undefined' && Symbol.toStringTag) {
/******/ 				Object.defineProperty(exports, Symbol.toStringTag, { value: 'Module' });
/******/ 			}
/******/ 			Object.defineProperty(exports, '__esModule', { value: true });
/******/ 		};
/******/ 	})();
/******/ 	
/************************************************************************/
var __webpack_exports__ = {};
// This entry needs to be wrapped in an IIFE because it needs to be isolated against other modules in the chunk.
(() => {
/*!**********************************************!*\
  !*** ./assets/src/js/settings-page/index.js ***!
  \**********************************************/
__webpack_require__.r(__webpack_exports__);
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _settings_app__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./settings-app */ "./assets/src/js/settings-page/settings-app.js");
/* harmony import */ var _settings_tab_registry__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ./settings-tab-registry */ "./assets/src/js/settings-page/settings-tab-registry.js");
/**
 * Settings page, built with @wordpress/components.
 *
 * Reads the acfSettingsPage data localized by SCF\Settings_Page\Settings_Page and exposes
 * acf.settingsPage.registerTab() for tabs that render their own UI.
 *
 * @since SCF 6.9.10
 */

/* global acfSettingsPage */





// Tab scripts load after this one and register their UI straight away.
(0,_settings_tab_registry__WEBPACK_IMPORTED_MODULE_2__.exposeRegistry)(window);
const mount = () => {
  const container = document.getElementById('acf-settings-app');
  if (!container || typeof acfSettingsPage === 'undefined') {
    return;
  }
  const app = /*#__PURE__*/React.createElement(_settings_app__WEBPACK_IMPORTED_MODULE_1__["default"], {
    initialConfig: acfSettingsPage
  });
  if (_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.createRoot) {
    (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.createRoot)(container).render(app);
  } else {
    (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.render)(app, container);
  }
};
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', mount);
} else {
  mount();
}
})();

/******/ })()
;
//# sourceMappingURL=scf-settings.js.map