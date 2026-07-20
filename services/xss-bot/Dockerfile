# Imagen oficial de Playwright: incluye Chromium, Node.js y todas las dependencias del sistema.
FROM mcr.microsoft.com/playwright:v1.44.1-jammy

WORKDIR /app

COPY package.json ./
RUN npm install

COPY bot.js ./

CMD ["node", "bot.js"]
