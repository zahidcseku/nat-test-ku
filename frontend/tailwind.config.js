/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./index.html",
    "./contact.html",
    "./news.html",
    "./registration.html",
    "./resources.html",
    "./results.html",
    "./debug.html",
  ],
  darkMode: "class",
  theme: {
    extend: {
      colors: {
        primary: "#002147",
        "on-primary": "#FFFFFF",
        surface: "#F8FAFC",
        "surface-container-low": "#F2F4F6",
        "surface-container-lowest": "#FFFFFF",
        "surface-container-high": "#E0E3E5",
        "surface-container-highest": "#E0E3E5",
        outline: "#74777f",
        "outline-variant": "#C4C6CF",
        secondary: "#515f74",
        "primary-container": "#002147",
        "on-primary-container": "#708ab5",
        accent: "#C4A962",
        "accent-light": "#E8D5A9",
      },
      fontFamily: {
        sans: ["DM Sans", "Public Sans", "sans-serif"],
        serif: ["Cormorant Garamond", "serif"],
      },
      animation: {
        "fade-in-up": "fadeInUp 0.8s ease-out forwards",
        "fade-in": "fadeIn 1s ease-out forwards",
        "slide-in-right": "slideInRight 0.6s ease-out forwards",
        "slide-in-left": "slideInLeft 0.6s ease-out forwards",
      },
      keyframes: {
        fadeInUp: {
          "0%": { opacity: "0", transform: "translateY(40px)" },
          "100%": { opacity: "1", transform: "translateY(0)" },
        },
        fadeIn: {
          "0%": { opacity: "0" },
          "100%": { opacity: "1" },
        },
        slideInRight: {
          "0%": { opacity: "0", transform: "translateX(60px)" },
          "100%": { opacity: "1", transform: "translateX(0)" },
        },
        slideInLeft: {
          "0%": { opacity: "0", transform: "translateX(-60px)" },
          "100%": { opacity: "1", transform: "translateX(0)" },
        },
      },
    },
  },
  plugins: [],
}
