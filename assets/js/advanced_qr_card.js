/**
 * advanced_qr_card.js
 * Renders high-fidelity, artistic watermark QR cards matching user's exact sample:
 * - Header: Small circular Sunday school logo + "አጸደ ትጉሃን ሰንበት ትምህርት ቤት"
 * - Background Watermark: Full circular Sunday school crest with tuned opacity
 * - Center: Pure transparent-background QR matrix (NO center logo blocking dots!)
 * - Title: Prominent Amharic book title
 * - Bottom: Elegant pill badge with "ID: [copy_code]"
 */

(function(window) {
  function drawRoundedRect(ctx, x, y, width, height, radius) {
    ctx.beginPath();
    ctx.moveTo(x + radius, y);
    ctx.lineTo(x + width - radius, y);
    ctx.quadraticCurveTo(x + width, y, x + width, y + radius);
    ctx.lineTo(x + width, y + height - radius);
    ctx.quadraticCurveTo(x + width, y + height, x + width - radius, y + height);
    ctx.lineTo(x + radius, y + height);
    ctx.quadraticCurveTo(x, y + height, x, y + height - radius);
    ctx.lineTo(x, y + radius);
    ctx.quadraticCurveTo(x, y, x + radius, y);
    ctx.closePath();
  }

  function renderWatermarkQrCard(canvas, options) {
    if (!canvas || typeof QRCode === 'undefined') return;

    const w = options.width || 360;
    const h = options.height || 490;
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d');

    // 1. Draw crisp white card background with rounded corners
    ctx.fillStyle = '#ffffff';
    drawRoundedRect(ctx, 2, 2, w - 4, h - 4, 18);
    ctx.fill();
    ctx.strokeStyle = '#e2e8f0';
    ctx.lineWidth = 2;
    ctx.stroke();

    // 2. Top Header: Small circular logo + text "አጸደ ትጉሃን ሰንበት ትምህርት ቤት"
    const headerY = 26;
    const logoR = 13;
    const logoX = 32;
    const logoImg = options.logoImg;

    if (logoImg && logoImg.complete && logoImg.naturalWidth > 0) {
      ctx.save();
      ctx.beginPath();
      ctx.arc(logoX, headerY, logoR, 0, Math.PI * 2);
      ctx.clip();
      ctx.drawImage(logoImg, logoX - logoR, headerY - logoR, logoR * 2, logoR * 2);
      ctx.restore();

      // Border around top-left small logo
      ctx.strokeStyle = '#d4af37';
      ctx.lineWidth = 1.2;
      ctx.beginPath();
      ctx.arc(logoX, headerY, logoR, 0, Math.PI * 2);
      ctx.stroke();
    }

    ctx.fillStyle = '#7f1d1d'; // Traditional Ethiopian church burgundy/red
    ctx.font = 'bold 13.5px "Noto Serif Ethiopic", "Noto Sans Ethiopic", sans-serif';
    ctx.textAlign = 'left';
    ctx.textBaseline = 'middle';
    ctx.fillText('አጸደ ትጉሃን ሰንበት ትምህርት ቤት', logoX + logoR + 8, headerY);

    // Center header text: "ቤተ ይትባረክ" right above the QR matrix
    ctx.fillStyle = '#0f172a';
    ctx.font = 'bold 18px "Noto Serif Ethiopic", "Noto Sans Ethiopic", sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText('ቤተ ይትባረክ', w / 2, 60);

    // 3. Background Watermark: Large circular Sunday school logo
    const qrCenterY = 222;
    const bgLogoSize = 280;
    if (logoImg && logoImg.complete && logoImg.naturalWidth > 0) {
      ctx.save();
      ctx.globalAlpha = typeof options.watermarkOpacity === 'number' ? options.watermarkOpacity : 0.42;
      ctx.drawImage(
        logoImg,
        (w - bgLogoSize) / 2,
        qrCenterY - (bgLogoSize / 2),
        bgLogoSize,
        bgLogoSize
      );
      ctx.restore();
    }

    // 4. Generate QR Matrix using qrcodejs
    const tempDiv = document.createElement('div');
    const qrInstance = new QRCode(tempDiv, {
      text: options.text,
      correctLevel: QRCode.CorrectLevel.H
    });

    if (qrInstance && qrInstance._oQRCode) {
      const qrModel = qrInstance._oQRCode;
      const count = qrModel.getModuleCount();
      const qrSize = options.qrSize || 230;
      const qrX = (w - qrSize) / 2;
      const qrY = qrCenterY - (qrSize / 2);
      const cellSize = qrSize / count;

      // Subtle translucent white halo behind finder patterns so phone cameras instantly lock on
      ctx.fillStyle = 'rgba(255, 255, 255, 0.90)';
      // Top-left finder
      ctx.fillRect(qrX - 3, qrY - 3, (cellSize * 7) + 6, (cellSize * 7) + 6);
      // Top-right finder
      ctx.fillRect(qrX + ((count - 7) * cellSize) - 3, qrY - 3, (cellSize * 7) + 6, (cellSize * 7) + 6);
      // Bottom-left finder
      ctx.fillRect(qrX - 3, qrY + ((count - 7) * cellSize) - 3, (cellSize * 7) + 6, (cellSize * 7) + 6);

      // Draw dark modules - NO center logo blocking dots as requested!
      ctx.fillStyle = '#0a0f1d';
      for (let r = 0; r < count; r++) {
        for (let c = 0; c < count; c++) {
          if (qrModel.isDark(r, c)) {
            const px = qrX + (c * cellSize);
            const py = qrY + (r * cellSize);
            ctx.fillRect(px, py, cellSize + 0.35, cellSize + 0.35);
          }
        }
      }
    }

    // 5. Book Title prominently under QR code
    const titleY = qrCenterY + 115 + 26;
    ctx.fillStyle = '#0f172a';
    ctx.font = 'bold 18px "Noto Serif Ethiopic", "Noto Sans Ethiopic", sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';

    let title = options.title || '';
    if (title.length > 26) {
      title = title.substring(0, 24) + '...';
    }
    ctx.fillText(title, w / 2, titleY);

    // 6. Bottom Badge Box: "ID: [copy_code]"
    const badgeY = titleY + 38;
    const badgeW = 144;
    const badgeH = 34;
    const badgeX = (w - badgeW) / 2;

    ctx.fillStyle = '#e2e8f0';
    drawRoundedRect(ctx, badgeX, badgeY - (badgeH / 2), badgeW, badgeH, 10);
    ctx.fill();
    ctx.strokeStyle = '#cbd5e1';
    ctx.lineWidth = 1.5;
    ctx.stroke();

    ctx.fillStyle = '#0f172a';
    ctx.font = 'bold 16px "IBM Plex Mono", monospace, sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText('ID: ' + (options.copyCode || '—'), w / 2, badgeY);
  }

  window.renderWatermarkQrCard = renderWatermarkQrCard;
})(window);
