<?php

echo "=================================\n";
echo "Qwen Singapore Region Setup\n";
echo "=================================\n\n";

echo "Please follow these steps:\n\n";

echo "1. Go to Alibaba Cloud DashScope Console:\n";
echo "   https://dashscope.console.aliyun.com/\n\n";

echo "2. Check/Change Region to Singapore:\n";
echo "   - Look at top-right corner\n";
echo "   - Should show 'Singapore' or 'ap-southeast-1'\n";
echo "   - If not, click region dropdown and select Singapore\n\n";

echo "3. Generate New API Key:\n";
echo "   - Go to API Key section\n";
echo "   - Delete old key: sk-856973f7e7404e2ca25328968eed3f23\n";
echo "   - Create new key for Singapore region\n";
echo "   - Copy the new key\n\n";

echo "4. Update your .env file:\n";
echo "   QWEN_API_KEY=your-new-singapore-api-key\n\n";

echo "5. Test the connection:\n";
echo "   php artisan config:clear\n";
echo "   php test-qwen-api.php\n\n";

echo "=================================\n";
echo "Alternative: Use Different AI Service\n";
echo "=================================\n\n";

echo "If Singapore region doesn't work, I can help you integrate:\n\n";

echo "Option 1: OpenAI (Easy Setup)\n";
echo "- Get API key from: https://platform.openai.com/\n";
echo "- Cost: ~$0.50-2 per 1000 generations\n";
echo "- Quality: Excellent\n\n";

echo "Option 2: Google Gemini (Fast & Cheap)\n";
echo "- Get API key from: https://aistudio.google.com/\n";
echo "- Cost: ~$0.10-0.30 per 1000 generations\n";
echo "- Quality: Very Good\n\n";

echo "Option 3: Anthropic Claude\n";
echo "- Get API key from: https://console.anthropic.com/\n";
echo "- Cost: ~$0.80-1.50 per 1000 generations\n";
echo "- Quality: Excellent for creative writing\n\n";

echo "Would you like me to implement one of these alternatives?\n";
echo "Just let me know which one you prefer!\n\n";

echo "=================================\n";