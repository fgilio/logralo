/** @type {import("prettier").Config} */
export default {
  proseWrap: "never",
  plugins: ["prettier-plugin-organize-imports", "prettier-plugin-tailwindcss"],
  overrides: [
    { files: ["*.md", "*.mdx"], options: { embeddedLanguageFormatting: "off" } },
    { files: ["*.yml", "*.yaml"], options: { proseWrap: "preserve" } },
  ],
};
