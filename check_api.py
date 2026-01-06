
import os
import sys
from openai import OpenAI

# A chave que você passou
check_key = os.getenv("OPENAI_API_KEY", "")

print(f"Verificando validade da chave OpenAI...")
client = OpenAI(api_key=check_key)

try:
    # Teste leve: listar modelos (não gasta crédito relevante)
    client.models.list()
    print("✅ SUCESSO: A chave OpenAI é VÁLIDA!")
except Exception as e:
    print(f"❌ ERRO: A chave OpenAI parece INVÁLIDA ou EXPIRADA.")
    print(f"Detalhe do erro: {e}")
