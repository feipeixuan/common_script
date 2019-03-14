#!/usr/bin/python
# coding=utf-8
import sys
import os
from PIL import Image
import imagehash
import distance
from shutil import copyfile


# 相似图片发现器
class SimFinder:

    def __init__(self):
        self.groups = []
        self.indexs = []
        for i in range(0, 8):
            self.indexs.append({})

    # 产生图片指纹
    def getFinger(self, image):
        hash = imagehash.average_hash(Image.open(image), hash_size=16)
        return str(hash)

    # 对图片进行聚类
    def clusterPhotos(self, baseDir, imageList):
        for image in imageList:
            # 计算指纹
            finger = self.getFinger(baseDir + image)
            group = self.getGroup(finger, image)
            if group == None:
                self.addGroup(finger, image)

    # 获取高相似度图片
    def getSimPhotos(self, baseDir, imageList):
        simPhotos = []
        self.clusterPhotos(baseDir, imageList)

        for group in self.groups:
            for finger in group.keys():
                if (len(group[finger]) >= 5):
                    simPhotos.extend(group[finger])

        return simPhotos

    # 获取单独图片
    def getAlonePhotos(self, baseDir, imageList):
        alonePhotos = []
        self.clusterPhotos(baseDir, imageList)

        for group in self.groups:
            for finger in group.keys():
                if (len(group[finger]) < 5):
                    alonePhotos.extend(group[finger])

        return alonePhotos

    # 获取组
    def getGroup(self, finger, image):
        for i in range(0, 8):
            subFinger = finger[i * 8:(i + 1) * 8]
            if subFinger not in self.indexs[i]:
                continue
            for groupIndex in self.indexs[i][subFinger]:
                fingerTmp = self.groups[groupIndex].keys()[0]
                if self.computeDiff(fingerTmp, finger) <= 10:
                    self.groups[groupIndex][fingerTmp].append(image)
                    return self.groups[groupIndex]
        return None

    # 添加组
    def addGroup(self, finger, image):
        # 添加group
        groupIndex = len(self.groups)
        self.groups.append({finger: [image]})
        # 添加索引
        for i in range(0, 8):
            subFinger = finger[i * 8:(i + 1) * 8]
            if subFinger not in self.indexs[i]:
                self.indexs[i][subFinger] = []
            self.indexs[i][subFinger].append(groupIndex)

    # 计算两个指纹的距离
    def computeDiff(self, hash1, hash2):
        return distance.hamming(str(hash1), str(hash2))


def main():
    inputDir = sys.argv[1]
    outputDir = sys.argv[2]
    strategy = sys.argv[3]
    simfinder = SimFinder()
    photoList = os.listdir(inputDir)
    if strategy == "sim":
        photoList = simfinder.getSimPhotos(inputDir,photoList)
    elif strategy == "alone":
        photoList = simfinder.getAlonePhotos(inputDir, photoList)
    for photoUrl in photoList:
        copyfile(inputDir + "/" + photoUrl, outputDir + "/" + photoUrl)

if __name__ == '__main__':
    main()
