import prepareInputField from "./components/Input";
import { closeList } from "./controllers/listController";
import resultsList from "./controllers/resultsController";
import { getInputValue, prepareQuery } from "./controllers/inputController";
import findMatches from "./controllers/dataController";
import checkTriggerCondition from "./controllers/triggerController";
import debouncer from "./utils/debouncer";
import eventEmitter from "./utils/eventEmitter";

/**
 * @desc This is autoComplete.js
 * @version 9.1
 * @example const autoCompleteJS = new autoComplete({config});
 */
export default class autoComplete {
  constructor(config) {
    // Deconstructing config values
    const {
      selector = "#autoComplete",
      placeHolder,
      observer,
      data: { src, key, cache, store, results },
      query,
      trigger: { event = ["input"], condition } = {},
      threshold = 1,
      debounce = 0,
      diacritics,
      searchEngine,
      feedback,
      resultsList: {
        render: resultsListRender = true,
        container,
        destination = selector,
        position = "afterend",
        element: resultsListElement = "ul",
        idName: resultsListId = "autoComplete_list",
        className: resultsListClass,
        maxResults = 5,
        navigation,
        noResults,
      } = {},
      resultItem: {
        content,
        element: resultItemElement = "li",
        idName: resultItemId,
        className: resultItemClass = "autoComplete_result",
        highlight: { render: highlightRender, className: highlightClass = "autoComplete_highlighted" } = {},
        selected: { className: selectedClass = "autoComplete_selected" } = {},
      } = {},
      onSelection, // Action function on result selection
    } = config;

    // Assigning config values to properties
    this.selector = selector;
    this.observer = observer;
    this.placeHolder = placeHolder;
    this.data = { src, key, cache, store, results };
    this.query = query;
    this.trigger = { event, condition };
    this.threshold = threshold;
    this.debounce = debounce;
    this.diacritics = diacritics;
    this.searchEngine = searchEngine;
    this.feedback = feedback;
    this.resultsList = {
      render: resultsListRender,
      container,
      destination: destination,
      position,
      element: resultsListElement,
      idName: resultsListId,
      className: resultsListClass,
      maxResults: maxResults,
      navigation,
      noResults: noResults,
    };
    this.resultItem = {
      content,
      element: resultItemElement,
      idName: resultItemId,
      className: resultItemClass,
      highlight: { render: highlightRender, className: highlightClass },
      selected: { className: selectedClass },
    };
    this.onSelection = onSelection;

    // Assign the "inputField" selector
    this.inputField = typeof this.selector === "string" ? document.querySelector(this.selector) : this.selector();
    // Invoke preInit function if enabled
    // or initiate autoComplete instance directly
    this.observer ? this.preInit() : this.init();
  }

  /**
   * Run autoComplete processes
   *
   * @param {String} - Raw "inputField" value as a string
   * @param {Object} config - autoComplete configurations
   *
   * @return {void}
   */
  start(input, query) {
    const results = this.data.results ? this.data.results(findMatches(this, query)) : findMatches(this, query);
    // Prepare data feedback object
    const dataFeedback = { input, query, matches: results, results: results.slice(0, this.resultsList.maxResults) };
    /**
     * @emit {results} event on search response with results
     **/
    eventEmitter(this.inputField, dataFeedback, "results");
    // If "resultsList" NOT active
    if (!this.resultsList.render) return this.feedback(dataFeedback);
    // Generate & Render "resultsList"
    resultsList(this, dataFeedback);
  }

  async dataStore() {
    if (this.data.cache && this.data.store) return;

    this.data.store = typeof this.data.src === "function" ? await this.data.src() : this.data.src;
    /**
     * @emit {fetch} event on data request
     **/
    eventEmitter(this.inputField, this.data.store, "fetch");
  }

  /**
   * Run autoComplete composer
   *
   * @param {Object} event - Trigger event Object
   *
   * @return {void}
   */
  async compose(event) {
    // Prepare raw "inputField" value
    const input = getInputValue(this.inputField);
    // Prepare manipulated query value
    const query = prepareQuery(input, this);
    // Get trigger decision
    const triggerCondition = checkTriggerCondition(this, event, query);
    // Validate trigger condition
    if (triggerCondition) {
      // Prepare data
      await this.dataStore();
      // Start autoComplete engine
      this.start(input, query);
    } else {
      // Close open list
      closeList(this);
    }
  }

  // Initialization stage
  init() {
    // Set "inputField" attributes
    prepareInputField(this);
    // Set placeholder attribute value
    if (this.placeHolder) this.inputField.setAttribute("placeholder", this.placeHolder);
    // Run executer
    this.hook = debouncer((event) => {
      // Prepare autoComplete processes
      this.compose(event);
    }, this.debounce);
    /**
     * @listen {input} events of "inputField"
     **/
    this.trigger.event.forEach((eventType) => {
      this.inputField.addEventListener(eventType, this.hook);
    });
    /**
     * @emit {init} event on Initialization
     **/
    eventEmitter(this.inputField, null, "init");
  }

  // Pre-Initialization stage
  preInit() {
    // Callback function to execute when mutations are observed
    const callback = (mutations, observer) => {
      mutations.forEach((mutation) => {
        // Check if "inputField" added to the DOM
        if (this.inputField) {
          // If yes disconnect the observer
          observer.disconnect();
          // Initialize autoComplete
          this.init();
        }
      });
    };
    // Create mutation observer instance
    const observer = new MutationObserver(callback);
    // Start observing the entire DOM until "inputField" is present
    // The entire document will be observed for all changes
    observer.observe(document, { childList: true, subtree: true });
  }

  // Un-initialize autoComplete
  unInit() {
    // Remove all autoComplete "inputField" eventListeners
    this.trigger.event.forEach((eventType) => {
      this.inputField.removeEventListener(eventType, this.hook);
    });
    /**
     * @emit {unInit} event on "inputField" detachment
     **/
    eventEmitter(this.inputField, null, "unInit");
  }
}
