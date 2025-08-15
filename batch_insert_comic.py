import os
import pymysql
import time

DB = {
    "host": "localhost",
    "user": "root",
    "password": "wendage123",
    "database": "audio_novel_db",
    "charset": "utf8mb4"
}

# 封面图片文件夹路径
ROOT = r"D:\下载\初夜视频\新建文件夹\ThinkPHP6\public\upload\novel"

def update_novel_cover(conn, novel_id: int, cover_url: str):
    with conn.cursor() as cursor:
        sql = "UPDATE text_novel SET cover_url = %s WHERE id = %s"
        cursor.execute(sql, (cover_url, novel_id))
    conn.commit()

def get_cover_path(novel_path, novel_id):
    for file in os.listdir(novel_path):
        if file.lower().endswith(('.jpg', '.jpeg', '.png', '.webp', '.gif')):
            return f"/upload/novel/{novel_id}/{file}"
    return ""

def main():
    conn = pymysql.connect(**DB)
    count = 0
    for folder in os.listdir(ROOT):
        folder_path = os.path.join(ROOT, folder)
        if not os.path.isdir(folder_path) or not folder.isdigit():
            continue
        cover = get_cover_path(folder_path, folder)
        if cover:
            update_novel_cover(conn, int(folder), cover)
            print(f"[OK] ID {folder} 更新封面为 {cover}")
            count += 1
        else:
            print(f"[SKIP] ID {folder} 没有找到封面图")
    conn.close()
    print(f"\n共更新 {count} 个小说封面")

if __name__ == "__main__":
    main()
