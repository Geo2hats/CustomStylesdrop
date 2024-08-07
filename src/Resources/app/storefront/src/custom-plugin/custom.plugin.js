import Plugin from "src/plugin-system/plugin.class";

export default class CustomPlugin extends Plugin {
  static options = {
    advancedPrice: null,
    unitPrice: null,
  };

  init() {
    this._registerEvents();

    const productDesignPriceSpan = document.querySelector(
      "#productDesignerPrice"
    );

    if (productDesignPriceSpan) {
      productDesignPriceSpan.parentElement.style.display = "none";
    }
  }

  /**
   * Registers event listeners for messages from the Product Designer plugin.
   *
   * @return {void}
   */
  _registerEvents() {
    window.addEventListener("message", this._handleMessage.bind(this), false);
  }

  /**
   * Handles the incoming message event from the Product Designer plugin.
   *
   * @param {Event} event - The message event object.
   * @return {void} This function does not return anything.
   */
  _handleMessage(event) {
    try {
      const {
        isProductDesigner,
        event: eventName,
        params: { price } = {},
      } = JSON.parse(event.data) || {};

      if (isProductDesigner && eventName === "finish") {
        this.updatePrice(price);
        this.updateAdvancedPricesWithDiscount(price);
      }
    } catch (error) {
      return;
    }
  }

  /**
   * Updates the price display based on the given price value.
   *
   * @param {number} newPrice - The new price value to be displayed.
   * @return {void} This function does not return anything.
   */
  updatePrice(newPrice) {
    const priceElement =
      document.querySelector(
        ".product-detail-price-container > .product-detail-price"
      )||
      document.querySelector(
        ".mabp-current-price > .product-detail-price > .mabp-current-price-value"
      );

    const currentPriceContent = priceElement.innerHTML.replace("&nbsp;", " ");
    const currentPrice = this.extractPrice(currentPriceContent);

    const formattedPrice = this.formatPrice(
      this.options.unitPrice + newPrice,
      currentPriceContent
    );
    priceElement.innerHTML = currentPriceContent.replace(
      currentPrice,
      formattedPrice
    );
  }

  /**
   * Updates the advanced prices with discount based on the given price value.
   *
   * @param {number} price - The new price value to be used for discount calculation.
   * @return {void} This function does not return anything.
   */
  updateAdvancedPricesWithDiscount(price) {
    if (!this.options.advancedPrice) {
      return;
    }

    const advancedPrices = document.querySelectorAll(".mabp-price-table-row");
    const advancedPricesLength = advancedPrices.length;

    for (let i = 0; i < advancedPricesLength; i++) {
      const row = advancedPrices[i];
      const priceValue = row.querySelector(".product-detail-price");
      const priceValueContent = priceValue.innerHTML.replace("&nbsp;", " ");

      const quantityFrom = parseInt(row.dataset.from);
      const quantityTo = parseInt(row.dataset.to);

      const advPrice = this.options.advancedPrice.find(
        (advPrice) =>
          advPrice.extensions.maxiaAdvBlockPrices.from === quantityFrom &&
          (advPrice.extensions.maxiaAdvBlockPrices.to === quantityTo ||
            advPrice.extensions.maxiaAdvBlockPrices.to === null)
      );

      if (!advPrice) {
        continue;
      }

      const discountRate =
        advPrice.extensions.maxiaAdvBlockPrices.savingsPercent;
      if (!discountRate) {
        continue;
      }

      const discountedPrice = price - (price * discountRate) / 100;
      const newPrice = advPrice.unitPrice + discountedPrice;
      const formattedPrice = this.formatPrice(newPrice, priceValueContent);

      priceValue.textContent = priceValueContent.replace(
        this.extractPrice(priceValueContent),
        formattedPrice
      );

      row.dataset.price = this.extractPrice(priceValueContent);
    }
  }

  /**
   * Extracts the price from a given content string.
   *
   * @param {string} content - The content string from which to extract the price.
   * @return {string} The extracted price as a string.
   */
  extractPrice(content) {
    const chunks = content.split(" ");

    for (let index = 0; index < chunks.length; index++) {
      const chunk = chunks[index];

      // Check for German format (e.g., "21,00")
      if (chunk.match(/^\d+,\d{2}$/)) {
        return chunk;
      }

      // Check for English format (e.g., "21.00")
      if (chunk.match(/^\d+\.\d{2}$/)) {
        return chunk;
      }

      // Check for English format with currency symbol (e.g., "€21.00")
      if (chunk.match(/^[^\d]+\d+\.\d{2}$/)) {
        return chunk.match(/\d+\.\d{2}$/)[0];
      }
    }

    return "0.00";
  }
  /**
   * Returns the decimal separator from the given content.
   *
   * @param {string} content - The content to search for the decimal separator.
   * @return {string} The decimal separator found in the content.
   */
  getDecimalSeparator(content) {
    return content.match(/\.|,/)[0];
  }

  /**
   * Formats the price with the correct decimal separator based on the content.
   *
   * @param {number} price - The price value to be formatted.
   * @param {string} content - The content to extract the decimal separator from.
   * @return {string} The formatted price with the correct decimal separator.
   */
  formatPrice(price, content) {
    const decimalSeparator = this.getDecimalSeparator(content);
    return price.toFixed(2).replace(".", decimalSeparator);
  }
}
